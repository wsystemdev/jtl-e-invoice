CREATE TABLE IF NOT EXISTS `xplugin_wsystem_einvoice_invoices` (
  `kBestellung` INT UNSIGNED NOT NULL,
  `data` MEDIUMTEXT NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`kBestellung`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
