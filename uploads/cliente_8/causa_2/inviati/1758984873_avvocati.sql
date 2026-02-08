-- phpMyAdmin SQL Dump
-- version 5.1.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:8889
-- Creato il: Set 13, 2025 alle 08:15
-- Versione del server: 5.7.34
-- Versione PHP: 8.0.8

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `avvocati`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `categorie_file`
--

CREATE TABLE `categorie_file` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descrizione` varchar(255) DEFAULT NULL,
  `colore` varchar(12) DEFAULT NULL,
  `attivo` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dump dei dati per la tabella `categorie_file`
--

INSERT INTO `categorie_file` (`id`, `nome`, `descrizione`, `colore`, `attivo`) VALUES
(1, 'Atto', 'Atti principali', '#0d6efd', 1),
(2, 'Decreto', 'Decreti del giudice', '#6610f2', 1),
(3, 'Perizia', 'Perizie tecniche', '#198754', 1),
(4, 'Altro', 'Misc', '#6c757d', 1);

-- --------------------------------------------------------

--
-- Struttura della tabella `cause`
--

CREATE TABLE `cause` (
  `id` int(11) NOT NULL,
  `id_cliente` int(11) NOT NULL,
  `titolo` varchar(200) NOT NULL,
  `autorita` varchar(200) DEFAULT NULL,
  `numero_rg` varchar(100) DEFAULT NULL,
  `data_inizio` date DEFAULT NULL,
  `data_fine` date DEFAULT NULL,
  `descrizione` text,
  `status` enum('aperte','chiuse','sospese') DEFAULT 'aperte',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cause_file`
--

CREATE TABLE `cause_file` (
  `id` int(11) NOT NULL,
  `id_causa` int(11) NOT NULL,
  `nome_originale` varchar(255) NOT NULL,
  `path_relativo` varchar(255) NOT NULL,
  `size_bytes` bigint(20) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `clienti`
--

CREATE TABLE `clienti` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `cognome` varchar(100) NOT NULL,
  `cf` varchar(16) DEFAULT NULL,
  `piva` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `indirizzo` varchar(255) DEFAULT NULL,
  `cap` varchar(10) DEFAULT NULL,
  `citta` varchar(100) DEFAULT NULL,
  `provincia` varchar(2) DEFAULT NULL,
  `note` text,
  `stato` enum('attivo','archiviato') DEFAULT 'attivo',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `utenti`
--

CREATE TABLE `utenti` (
  `id` int(11) NOT NULL,
  `nome` varchar(255) NOT NULL,
  `cognome` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `cod_fis` varchar(16) DEFAULT NULL,
  `p_iva` int(13) DEFAULT NULL,
  `indirizzo` text,
  `cap` int(5) DEFAULT NULL,
  `citta` text,
  `provincia` varchar(5) DEFAULT NULL,
  `nazione` varchar(255) DEFAULT NULL,
  `pec` varchar(255) DEFAULT NULL,
  `sdi` varchar(255) DEFAULT NULL,
  `docRimanenti` int(11) DEFAULT NULL,
  `status` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dump dei dati per la tabella `utenti`
--

INSERT INTO `utenti` (`id`, `nome`, `cognome`, `email`, `password`, `cod_fis`, `p_iva`, `indirizzo`, `cap`, `citta`, `provincia`, `nazione`, `pec`, `sdi`, `docRimanenti`, `status`) VALUES
(1, 'Alessio', 'Patamia', 'alessio.patamia@gmail.com', '$2y$10$iw4FOqnJN8qb1NP57FZHIeX0KnLUT0IeeKYQhfdC.hL/kOH1U4TWG', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'attesa');

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `categorie_file`
--
ALTER TABLE `categorie_file`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nome` (`nome`);

--
-- Indici per le tabelle `cause`
--
ALTER TABLE `cause`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_cliente` (`id_cliente`);

--
-- Indici per le tabelle `cause_file`
--
ALTER TABLE `cause_file`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_causa` (`id_causa`);

--
-- Indici per le tabelle `clienti`
--
ALTER TABLE `clienti`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `utenti`
--
ALTER TABLE `utenti`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `categorie_file`
--
ALTER TABLE `categorie_file`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT per la tabella `cause`
--
ALTER TABLE `cause`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cause_file`
--
ALTER TABLE `cause_file`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `clienti`
--
ALTER TABLE `clienti`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `utenti`
--
ALTER TABLE `utenti`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Limiti per le tabelle scaricate
--

--
-- Limiti per la tabella `cause`
--
ALTER TABLE `cause`
  ADD CONSTRAINT `cause_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `clienti` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `cause_file`
--
ALTER TABLE `cause_file`
  ADD CONSTRAINT `cause_file_ibfk_1` FOREIGN KEY (`id_causa`) REFERENCES `cause` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
