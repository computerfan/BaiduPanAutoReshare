CREATE TABLE IF NOT EXISTS `log_new` (
  `ID` int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `IP` varchar(15) NOT NULL,
  `level` tinyint(4) NOT NULL,
  `content` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `ID` int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `username` varchar(255) NOT NULL UNIQUE,
  `cookie` text NOT NULL,
  `bduss` tinytext NOT NULL,
  `newmd5` TEXT NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `watchlist` (
  `id` int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `fid` tinytext COLLATE utf8_unicode_ci NOT NULL,
  `name` text COLLATE utf8_unicode_ci NOT NULL,
  `link` tinytext COLLATE utf8_unicode_ci NOT NULL,
  `count` int(11) NOT NULL,
  `pass` varchar(4) COLLATE utf8_unicode_ci DEFAULT NULL,
  `user_id` int(11) DEFAULT '1',
  `failed` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;