#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import re
import sys
import os
import json
import copy
from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

# =========================
# LOG
# =========================
LOG_FILE = "log_elabora.txt"

def log(msg):
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(str(msg).rstrip() + "\n")
    except Exception:
        pass

# reset log
with open(LOG_FILE, "w", encoding="utf-8") as f:
    f.write("")

log(f"sys.argv: {sys.argv}")

# =========================
# ARGS
# =========================
# Uso:
# python linka_normattiva.py input.docx output.docx urn_index.json [filemap.json]
if len(sys.argv) not in (4, 5):
    log("❌ Uso: python linka_normattiva.py input.docx output.docx urn_index.json [filemap.json]")
    sys.exit(1)

input_path = sys.argv[1]
output_path = sys.argv[2]
urn_index_path = sys.argv[3]
filemap_path = sys.argv[4] if len(sys.argv) == 5 else None

if not os.path.isfile(input_path):
    log(f"❌ Input DOCX non trovato: {input_path}")
    sys.exit(2)

if not os.path.isfile(urn_index_path):
    log(f"❌ urn_index.json non trovato: {urn_index_path}")
    sys.exit(2)

# =========================
# LOAD URN DB
# =========================
with open(urn_index_path, encoding="utf-8") as f:
    urn_db = json.load(f)

# =========================
# LOAD FILEMAP (optional)
# =========================
file_items = []
if filemap_path and os.path.isfile(filemap_path):
    try:
        with open(filemap_path, encoding="utf-8") as f:
            fm = json.load(f)
        if isinstance(fm, dict):
            for k, v in fm.items():
                file_items.append({"name": str(k), "url": str(v)})
        elif isinstance(fm, list):
            for x in fm:
                if isinstance(x, dict) and x.get("name") and x.get("url"):
                    file_items.append({"name": str(x["name"]), "url": str(x["url"])})
    except Exception as e:
        log(f"⚠️ filemap.json non leggibile: {e}")

log(f"FILE_ITEMS: {file_items}")

# =========================
# HELPERS
# =========================
def roman_to_int(s):
    s = s.upper()
    vals = {'I':1,'V':5,'X':10,'L':50,'C':100,'D':500,'M':1000}
    total = 0
    prev = 0
    for ch in reversed(s):
        v = vals.get(ch, 0)
        if v < prev:
            total -= v
        else:
            total += v
            prev = v
    return total

_EXT_TOKEN = (
    "bis|ter|quater|quinquies|sexies|septies|octies|novies|decies|"
    "undecies|duodecies|terdecies|quaterdecies|quindecies|sexdecies"
)

_ART_RE = re.compile(
    rf"(?P<num>\d+)(?:[-–\.]?(?P<ext>{_EXT_TOKEN}))?",
    re.IGNORECASE
)

def parse_articolo(txt):
    m = _ART_RE.fullmatch(txt.strip())
    if not m:
        return txt.strip(), None
    return m.group("num"), (m.group("ext").lower() if m.group("ext") else None)

def lookup_urn_entry(raw_key):
    if not raw_key:
        return None
    key = re.sub(r"\.+", ".", raw_key.lower().replace(" ", ""))
    for cand in (key, key.rstrip("."), key + "."):
        if cand in urn_db:
            return urn_db[cand]
    return None

def build_urn(tipo, numero, data, articolo=None, estensione=None):
    base = "https://www.normattiva.it/uri-res/N2Ls?urn=nir:stato:"
    urn = f"{base}{tipo}:{data};{numero}"
    if articolo:
        urn += f"~art{articolo}"
        if estensione:
            urn += estensione
    return urn

def build_codice_urn(match):
    raw_art = match.group("art")
    raw_cod = match.group("cod")

    entry = lookup_urn_entry(raw_cod)
    if not entry:
        return None

    art_num, art_ext = parse_articolo(raw_art)

    return build_urn(
        tipo=entry["tipo"],
        numero=entry["numero"],
        data=entry["data"],
        articolo=art_num,
        estensione=art_ext
    )

def build_legge_urn(match, include_articolo=False):
    num = match.group("num")
    year = match.group("year")
    raw_key = f"l.{num}/{year}"
    entry = lookup_urn_entry(raw_key)
    warning = None
    if entry:
        tipo = entry["tipo"]
        numero = entry["numero"]
        data = entry["data"]
    else:
        tipo = "legge"
        numero = num
        data = f"{year}-01-01"
        warning = {
            "ref": match.group(0).strip(),
            "numero": num,
            "anno": year,
        }

    art_num = None
    art_ext = None
    if include_articolo:
        raw_art = match.group("art")
        art_num, art_ext = parse_articolo(raw_art)

    urn = build_urn(
        tipo=tipo,
        numero=numero,
        data=data,
        articolo=art_num,
        estensione=art_ext
    )
    return urn, warning

# =========================
# REGEX RULES (Normattiva)
# =========================
norm_rules = [
    # art. 1720 c.c., art. 15 c.p.) ecc.
    (
        re.compile(
            rf"\b(?:art\.?|articolo)\s+"
            rf"(?P<art>\d+(?:[-–\.]?(?:{_EXT_TOKEN}))?)\s+"
            rf"(?P<cod>c\.p\.p\.?|c\.p\.c\.?|c\.p\.?|c\.c\.?)"
            rf"(?=(?:\s|,|;|\.|:|\)|\]|$))",
            re.IGNORECASE
        ),
        lambda m: (m.group(0), build_codice_urn(m), None)
    ),

    # art. 5 L. 241/1990
    (
        re.compile(
            rf"\b(?:art\.?|articolo)\s+"
            rf"(?P<art>\d+(?:[-–\.]?(?:{_EXT_TOKEN}))?)\s+"
            r"(?:l\.?|legge)\s*(?:n\.|n°|nº|num\.|numero)?\s*"
            r"(?P<num>\d+)\s*/\s*(?P<year>\d{4})"
            r"(?=(?:\s|,|;|\.|:|\)|\]|$))",
            re.IGNORECASE
        ),
        lambda m: (m.group(0), *build_legge_urn(m, include_articolo=True))
    ),

    # L. 241/1990
    (
        re.compile(
            r"\b(?:l\.?|legge)\s*(?:n\.|n°|nº|num\.|numero)?\s*"
            r"(?P<num>\d+)\s*/\s*(?P<year>\d{4})"
            r"(?=(?:\s|,|;|\.|:|\)|\]|$))",
            re.IGNORECASE
        ),
        lambda m: (m.group(0), *build_legge_urn(m, include_articolo=False))
    ),

    # art. 24 Cost.
    (
        re.compile(
            r"\b(?:art\.?|articolo)\s+(?P<art>[IVXLCDM]+|\d+)\s+(?:Cost\.?|Costituzione)\b",
            re.IGNORECASE
        ),
        lambda m: (
            m.group(0),
            build_urn(
                tipo="costituzione",
                numero="",
                data="1947-12-27",
                articolo=str(roman_to_int(m.group("art")))
                if re.fullmatch(r"[IVXLCDM]+", m.group("art"), re.I)
                else m.group("art")
            ).replace(";", "", 1),
            None
        )
    ),
]

# =========================
# DOCX UTILS (STILE PRESERVATO)
# =========================
def _ensure_text_preserve(t_el, text):
    # Word mangia spazi ai bordi se non setti xml:space="preserve"
    if text.startswith(" ") or text.endswith(" "):
        t_el.set(qn("xml:space"), "preserve")

def _copy_rPr_from_run(run):
    # deep copy di rPr (stile run: bold/italic/underline/colore/font ecc.)
    rPr = run._r.rPr
    if rPr is None:
        return None
    return copy.deepcopy(rPr)

def _apply_force_hyperlink_style(rPr):
    # forza underline + colore anche se nel template Hyperlink è custom
    # underline
    u = rPr.find(qn("w:u"))
    if u is None:
        u = OxmlElement("w:u")
        rPr.append(u)
    u.set(qn("w:val"), "single")

    # colore blu
    c = rPr.find(qn("w:color"))
    if c is None:
        c = OxmlElement("w:color")
        rPr.append(c)
    c.set(qn("w:val"), "0000FF")

    # run style Hyperlink
    rs = rPr.find(qn("w:rStyle"))
    if rs is None:
        rs = OxmlElement("w:rStyle")
        rPr.append(rs)
    rs.set(qn("w:val"), "Hyperlink")

def _make_run_element(text, rPr_copy=None):
    r = OxmlElement("w:r")
    if rPr_copy is not None:
        r.append(rPr_copy)
    t = OxmlElement("w:t")
    t.text = text
    _ensure_text_preserve(t, text)
    r.append(t)
    return r

def _add_hyperlink_element(paragraph, url, text, template_rPr=None):
    part = paragraph.part
    r_id = part.relate_to(
        url,
        "http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink",
        is_external=True
    )

    hyperlink = OxmlElement("w:hyperlink")
    hyperlink.set(qn("r:id"), r_id)

    # rPr copiato dal run originale + forza hyperlink
    rPr = template_rPr if template_rPr is not None else OxmlElement("w:rPr")
    _apply_force_hyperlink_style(rPr)

    r = _make_run_element(text, rPr)
    hyperlink.append(r)
    paragraph._p.append(hyperlink)

def clear_paragraph_keep_pPr(paragraph):
    # rimuove tutto tranne pPr (stile paragrafo intatto)
    p = paragraph._p
    for child in list(p):
        if child.tag != qn("w:pPr"):
            p.remove(child)

# =========================
# FILEMAP MATCH (come tuo)
# =========================
def norm_spaces(s: str) -> str:
    return re.sub(r"\s+", " ", (s or "").strip())

def stem_and_ext(filename: str):
    b = os.path.basename(filename or "")
    if "." in b:
        stem = ".".join(b.split(".")[:-1])
        ext = b.split(".")[-1].lower()
        return stem, ext
    return b, ""

_RX_DOC_ALG = re.compile(
    r"^(?P<kind>documento|doc|allegato|all)\.?\s*(?:(?:n\.|n°|nº|num\.|numero)\s*)?(?P<num>\d+)$",
    re.IGNORECASE
)

def is_specific_stem(stem: str) -> bool:
    s = norm_spaces(stem)
    if len(s) >= 10:
        return True
    if re.search(r"\d", s):
        return True
    if len(s.split()) >= 2 and len(s) >= 7:
        return True
    return False

def _mk_aliases_documento(num: str, filename_full: str):
    out = set()
    if filename_full:
        out.add(filename_full)
    out.add(f"Documento {num}")
    out.add(f"Documento n. {num}")
    out.add(f"Documento n° {num}")
    out.add(f"Documento nº {num}")
    out.add(f"Doc {num}")
    out.add(f"Doc. {num}")
    return out

def _mk_aliases_allegato(num: str, filename_full: str):
    out = set()
    if filename_full:
        out.add(filename_full)
    out.add(f"Allegato {num}")
    out.add(f"Allegato n. {num}")
    out.add(f"Allegato n° {num}")
    out.add(f"Allegato nº {num}")
    out.add(f"All {num}")
    out.add(f"All. {num}")
    out.add(f"All.to {num}")
    out.add(f"All.to n. {num}")
    return out

def variants_for_name_generic(name: str):
    name = norm_spaces(name)
    base = os.path.basename(name)
    stem, _ext = stem_and_ext(base)
    stem = norm_spaces(stem)

    vars_ = set()
    if base:
        vars_.add(base)
        vars_.add(base.replace("_", " "))
        vars_.add(base.replace(" ", "_"))

    if stem and is_specific_stem(stem):
        vars_.add(stem)
        vars_.add(stem.replace("_", " "))
        vars_.add(stem.replace(" ", "_"))

    out = []
    for v in vars_:
        v = norm_spaces(v)
        if v:
            out.append(v)
    out.sort(key=lambda x: len(x), reverse=True)
    return out

def build_aliases_for_file(name: str):
    base = os.path.basename(norm_spaces(name))
    stem, _ext = stem_and_ext(base)
    stem_norm = norm_spaces(stem)
    m = _RX_DOC_ALG.match(stem_norm.replace("  ", " "))
    if m:
        kind = (m.group("kind") or "").lower()
        num = m.group("num")
        full = base
        if kind in ("documento", "doc"):
            return list(_mk_aliases_documento(num, full))
        if kind in ("allegato", "all"):
            return list(_mk_aliases_allegato(num, full))
    return variants_for_name_generic(base)

def compile_file_patterns(items):
    alias_to_urls = {}
    alias_to_original = {}

    for it in items:
        nm = (it.get("name") or "").strip()
        url = (it.get("url") or "").strip()
        if not nm or not url:
            continue

        aliases = build_aliases_for_file(nm)
        for a in aliases:
            a = norm_spaces(a)
            if not a:
                continue
            k = a.lower()
            alias_to_urls.setdefault(k, set()).add(url)
            if k not in alias_to_original:
                alias_to_original[k] = a

    safe_aliases = []
    ambiguous = []
    for k, urls in alias_to_urls.items():
        if len(urls) == 1:
            safe_aliases.append((alias_to_original.get(k, k), list(urls)[0]))
        else:
            ambiguous.append((alias_to_original.get(k, k), list(urls)))

    if ambiguous:
        log("⚠️ ALIAS AMBIGUI (non linkati):")
        for a, urls in ambiguous:
            log(f"  - {a} -> {urls}")

    pats = []
    seen = set()

    def _score(alias: str) -> int:
        s = alias.strip()
        bonus = 50 if re.search(r"\.[a-z0-9]{2,5}$", s, re.I) else 0
        return len(s) + bonus

    safe_aliases.sort(key=lambda x: _score(x[0]), reverse=True)

    for alias, url in safe_aliases:
        key = (alias.lower(), url)
        if key in seen:
            continue
        seen.add(key)
        rx = re.compile(rf"(?<!\w){re.escape(alias)}(?!\w)", re.IGNORECASE)
        pats.append((rx, url, alias))

    log(f"FILE_PATTERNS_COUNT: {len(pats)}")
    return pats

file_patterns = compile_file_patterns(file_items)

# =========================
# COLLECT MATCHES (merge + no overlap)
# =========================
def collect_matches(text):
    matches = []

    # Normattiva (codici + cost.)
    for pattern, handler in norm_rules:
        for m in pattern.finditer(text):
            label, link, warning = handler(m)
            if link:
                matches.append((m.start(), m.end(), label, link, "N", warning))

    # File links
    for rx, url, _alias in file_patterns:
        for m in rx.finditer(text):
            label = m.group(0)
            if label:
                matches.append((m.start(), m.end(), label, url, "F", None))

    def _key(x):
        s, e, _lbl, _url, typ, _warning = x
        return (s, -(e - s), 0 if typ == "N" else 1)

    matches.sort(key=_key)

    # overlap: tieni primo non sovrapposto
    out = []
    last_end = -1
    for s, e, lbl, url, typ, warning in matches:
        if s < last_end:
            continue
        out.append((s, e, lbl, url, typ, warning))
        last_end = e

    return out

# =========================
# PAGE BREAK UTILS
# =========================
def paragraph_has_page_break_before(paragraph):
    p_pr = paragraph._p.find(qn("w:pPr"))
    if p_pr is None:
        return False
    return p_pr.find(qn("w:pageBreakBefore")) is not None

def run_has_page_break(run):
    for br in run._r.findall(qn("w:br")):
        if br.get(qn("w:type")) == "page":
            return True
    if run._r.findall(qn("w:lastRenderedPageBreak")):
        return True
    return False

# =========================
# MAIN (PRESERVA RUN STYLE)
# =========================
doc = Document(input_path)

total_links = 0
links_norm = 0
links_files = 0
warnings = []
current_page = 1

for para in doc.paragraphs:
    if paragraph_has_page_break_before(para):
        current_page += 1

    # se non ci sono run, skip
    if not para.runs:
        continue

    # testo completo (unione dei run)
    runs = list(para.runs)
    full_text = "".join(r.text for r in runs)

    if not full_text.strip():
        continue

    run_page_breaks = {i for i, r in enumerate(para.runs) if run_has_page_break(r)}
    ms = collect_matches(full_text)
    if not ms:
        current_page += len(run_page_breaks)
        continue

    log(f"PARA: {full_text}")
    log(f"MATCHES: {ms}")

    # Costruiamo segmenti basati sul testo, ma scegliamo il rPr “di contesto”
    # per ogni segmento prendiamo il rPr del run che contiene l’inizio del segmento.
    # Mappa pos->(run_index, offset)
    pos_map = []
    for i, r in enumerate(runs):
        for j in range(len(r.text)):
            pos_map.append((i, j))
    # pos_map[k] = (run_idx, char_idx_in_run)

    def rPr_at_pos(pos):
        if pos < 0:
            pos = 0
        if pos >= len(pos_map):
            pos = len(pos_map) - 1
        ri, _ = pos_map[pos]
        return _copy_rPr_from_run(runs[ri])

    # Ricostruiamo il paragrafo mantenendo pPr (stile paragrafo intatto)
    clear_paragraph_keep_pPr(para)

    last = 0
    for start, end, label, link, typ, warning in ms:
        # testo “normale” prima del link
        if start > last:
            chunk = full_text[last:start]
            para._p.append(_make_run_element(chunk, rPr_at_pos(last)))

        # hyperlink: copia stile dal contesto (run in cui inizia il match)
        tpl = rPr_at_pos(start)
        _add_hyperlink_element(para, link, label, tpl)

        last = end
        total_links += 1
        if typ == "N":
            links_norm += 1
        else:
            links_files += 1
        if warning:
            run_index = pos_map[start][0] if pos_map else 0
            page_offset = sum(1 for idx in run_page_breaks if idx < run_index)
            warning_entry = dict(warning)
            warning_entry["page"] = current_page + page_offset
            warnings.append(warning_entry)

    # testo dopo ultimo match
    if last < len(full_text):
        chunk = full_text[last:]
        para._p.append(_make_run_element(chunk, rPr_at_pos(last)))
    current_page += len(run_page_breaks)

if os.path.exists(output_path):
    os.remove(output_path)

doc.save(output_path)

log(f"✅ COMPLETATO – link creati: {total_links} (norm={links_norm}, files={links_files})")
print(f"LINKS_CREATED={total_links}")
print(f"LINKS_NORMATTIVA={links_norm}")
print(f"LINKS_FILES={links_files}")
print("WARNINGS_JSON=" + json.dumps(warnings, ensure_ascii=False))
print(f"WARNINGS_COUNT={len(warnings)}")
sys.exit(0)
