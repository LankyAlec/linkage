# Python utilities

## Virtual environment setup

This repo does not ship a pre-built virtual environment. Create one locally before running the
scripts in this folder.

### macOS / Linux (bash/zsh)

```bash
python3 -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
```

### Windows (PowerShell)

```powershell
py -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
```

If activation fails, delete the `.venv` directory and re-run the steps above to recreate it.
