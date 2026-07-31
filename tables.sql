-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               11.8.2-MariaDB - mariadb.org binary distribution
-- Server OS:                    Win64
-- HeidiSQL Version:             12.10.0.7000
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Dumping structure for table cinius_nakitakis.adresdefteri
CREATE TABLE IF NOT EXISTS `adresdefteri` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `FirmaAd` varchar(145) DEFAULT NULL,
  `Ad` varchar(145) DEFAULT NULL,
  `Soyad` varchar(145) DEFAULT NULL,
  `Adres` longtext DEFAULT NULL,
  `Ilce` int(10) unsigned NOT NULL DEFAULT 0,
  `Sehir` int(10) unsigned NOT NULL DEFAULT 0,
  `VergiD` varchar(45) DEFAULT NULL,
  `VergiNo` varchar(245) DEFAULT NULL,
  `Telefon1` varchar(245) DEFAULT NULL,
  `Telefon2` varchar(245) DEFAULT NULL,
  `Telefon3` varchar(245) DEFAULT NULL,
  `GSMTel1` varchar(245) DEFAULT NULL,
  `GSMTel2` varchar(245) DEFAULT NULL,
  `Faks1` varchar(45) DEFAULT NULL,
  `Faks2` varchar(45) DEFAULT NULL,
  `StdAlisIndirim` int(11) NOT NULL DEFAULT 0,
  `IlgiliKisi` varchar(145) DEFAULT NULL,
  `Notlar` longtext DEFAULT NULL,
  `KayitTarihi` datetime DEFAULT NULL,
  `Kurumsal` int(10) unsigned NOT NULL DEFAULT 0,
  `Kisisel` int(10) unsigned NOT NULL DEFAULT 0,
  `Etkin` int(10) unsigned NOT NULL DEFAULT 0,
  `GrupID` int(10) unsigned NOT NULL DEFAULT 1,
  `StdSatisIndirim` int(10) unsigned NOT NULL DEFAULT 0,
  `kisaKod` varchar(15) DEFAULT NULL,
  `Semt` varchar(45) DEFAULT NULL,
  `Bakiye` double(10,2) DEFAULT 0.00,
  `listearama` varchar(250) DEFAULT NULL,
  `oncekiFaturalardanIndirimAl` int(10) unsigned NOT NULL DEFAULT 1,
  `ulkeID` int(10) unsigned NOT NULL DEFAULT 1,
  `sehirAD` varchar(145) DEFAULT NULL,
  `gizle` int(10) unsigned NOT NULL DEFAULT 0,
  `eposta` varchar(245) DEFAULT NULL,
  `fuaraKatiliyor` int(10) unsigned NOT NULL DEFAULT 0,
  `caridenDus` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `Index_2` (`FirmaAd`),
  KEY `Index_3` (`Ad`),
  KEY `Index_4` (`Soyad`),
  KEY `Index_5` (`listearama`),
  KEY `Index_6` (`gizle`)
) ENGINE=MyISAM AUTO_INCREMENT=5608 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.adresgruplari
CREATE TABLE IF NOT EXISTS `adresgruplari` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grupAdi` varchar(145) DEFAULT NULL,
  `grupIndirimi` int(10) unsigned NOT NULL DEFAULT 0,
  `urunIndirimKullan` varchar(5) NOT NULL DEFAULT '0',
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.adres_agac
CREATE TABLE IF NOT EXISTS `adres_agac` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `DalAd` varchar(80) NOT NULL,
  `UstDalID` int(10) unsigned NOT NULL,
  `IkonID` int(10) unsigned NOT NULL DEFAULT 1,
  `icerikSayi` int(10) unsigned NOT NULL DEFAULT 0,
  `level` int(10) unsigned NOT NULL DEFAULT 0,
  `altDallar` longtext DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `index_2` (`UstDalID`) USING BTREE
) ENGINE=MyISAM AUTO_INCREMENT=21 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.adres_defter_agac_iliskileri
CREATE TABLE IF NOT EXISTS `adres_defter_agac_iliskileri` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `musteriID` int(10) unsigned NOT NULL,
  `agacID` int(10) unsigned NOT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=5005 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.asp_urun
CREATE TABLE IF NOT EXISTS `asp_urun` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kod` int(10) unsigned DEFAULT 0,
  `isbn` varchar(15) DEFAULT NULL,
  `kitapadi` varchar(145) DEFAULT NULL,
  `altbaslik` varchar(145) DEFAULT NULL,
  `yayinevitxt` varchar(85) DEFAULT NULL,
  `yazarlistetxt` varchar(245) DEFAULT NULL,
  `konular` varchar(245) DEFAULT NULL,
  `dizi` varchar(65) DEFAULT NULL,
  `ozgundili` varchar(55) DEFAULT NULL,
  `boyut` varchar(25) DEFAULT NULL,
  `eder` double(6,2) DEFAULT 0.00,
  `aciklama` longtext DEFAULT NULL,
  `baskitarihi` varchar(25) DEFAULT NULL,
  `baskiyeri` varchar(35) DEFAULT NULL,
  `hamur` varchar(25) DEFAULT NULL,
  `sayfa` int(10) unsigned DEFAULT 0,
  `cilt` varchar(45) DEFAULT NULL,
  `xbaski` int(10) unsigned DEFAULT 0,
  `ozgunad` varchar(145) DEFAULT NULL,
  `kapakdosya` varchar(145) DEFAULT NULL,
  `satistayok` int(10) unsigned DEFAULT 0,
  `ozgunDosya` varchar(145) DEFAULT NULL,
  `barkod1` varchar(15) DEFAULT NULL,
  `ceviren` varchar(145) DEFAULT NULL,
  `indirim` int(10) unsigned DEFAULT 0,
  `kdv` int(10) unsigned DEFAULT 8,
  `birimID` int(10) unsigned DEFAULT 1,
  `varsayilanDepoID` int(10) unsigned DEFAULT 1,
  `hizmetUrunu` int(10) unsigned DEFAULT 0,
  `ureticiID` int(10) unsigned DEFAULT 1,
  `resimDosya` varchar(100) DEFAULT NULL,
  `eklemeTarihi` datetime DEFAULT NULL,
  `sonGuncelleme` datetime DEFAULT NULL,
  `fiyatKDVDahil` int(10) unsigned DEFAULT 1,
  `stokMiktar` int(11) DEFAULT 0,
  `kurID` int(10) unsigned DEFAULT 1,
  `urunGrupID` int(10) unsigned DEFAULT 1,
  `kaynakID` int(10) unsigned DEFAULT 0,
  `netkitapKod` int(12) unsigned DEFAULT 0,
  `birlesikad` varchar(245) DEFAULT NULL,
  `barkod2` varchar(15) DEFAULT NULL,
  `sondegisim` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `CinYazarID` int(10) unsigned DEFAULT 0,
  `satisMiktar` int(11) DEFAULT 0,
  `tedarikciIDler` varchar(55) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `index_2` (`kod`),
  KEY `index_3` (`isbn`),
  KEY `index_4` (`barkod1`),
  KEY `index_5` (`kaynakID`),
  KEY `Index_10` (`netkitapKod`),
  KEY `Index_11` (`barkod2`),
  FULLTEXT KEY `Index_6` (`kitapadi`),
  FULLTEXT KEY `Index_7` (`yayinevitxt`),
  FULLTEXT KEY `Index_8` (`yazarlistetxt`),
  FULLTEXT KEY `Index_9` (`birlesikad`)
) ENGINE=MyISAM AUTO_INCREMENT=211063 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci PACK_KEYS=0 ROW_FORMAT=FIXED;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.banka_kasa
CREATE TABLE IF NOT EXISTS `banka_kasa` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `para` double(10,2) unsigned NOT NULL DEFAULT 0.00,
  `tarih` datetime DEFAULT '0000-00-00 00:00:00',
  `hesapID` int(11) NOT NULL DEFAULT 0,
  `aciklama` varchar(255) DEFAULT '',
  `kurID` int(11) NOT NULL DEFAULT 1,
  `yatirildi` tinyint(4) NOT NULL DEFAULT 0,
  `cekildi` tinyint(4) NOT NULL DEFAULT 0,
  `assign` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'this field is abandoned.',
  `musteriID` int(11) NOT NULL DEFAULT 0,
  `transferBankaID` int(11) NOT NULL DEFAULT 0,
  `taksit` int(10) unsigned NOT NULL DEFAULT 0,
  `kategoriID` int(10) unsigned NOT NULL DEFAULT 0,
  `faturaID` int(10) unsigned DEFAULT 0,
  `kasaID` int(10) unsigned NOT NULL DEFAULT 0,
  `giderID` int(10) unsigned NOT NULL DEFAULT 0,
  `gelirID` int(10) unsigned NOT NULL DEFAULT 0,
  `cekID` int(10) unsigned NOT NULL DEFAULT 0,
  `firmaID` int(10) unsigned NOT NULL DEFAULT 0,
  `genelFirmaDir` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `bankaKendini` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `index_2` (`hesapID`),
  KEY `index_3` (`assign`),
  KEY `index_4` (`musteriID`)
) ENGINE=MyISAM AUTO_INCREMENT=5268 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci ROW_FORMAT=FIXED;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.birimler
CREATE TABLE IF NOT EXISTS `birimler` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `birim` varchar(45) DEFAULT NULL,
  `ondalikVar` int(10) unsigned NOT NULL DEFAULT 0,
  `altBirimID` int(10) unsigned NOT NULL DEFAULT 0,
  `altBirimCarpan` int(10) unsigned NOT NULL DEFAULT 0,
  `kisaltma` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.cari_duzeltme_kayitlari
CREATE TABLE IF NOT EXISTS `cari_duzeltme_kayitlari` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `musteriID` int(10) unsigned NOT NULL DEFAULT 0,
  `Aciklama` varchar(145) DEFAULT NULL,
  `CariSifirla` int(10) unsigned NOT NULL DEFAULT 0,
  `islemTarihi` datetime DEFAULT NULL,
  `Miktar` double(8,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`ID`),
  KEY `Index_2` (`musteriID`)
) ENGINE=MyISAM AUTO_INCREMENT=255 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.cek_senet_kasa
CREATE TABLE IF NOT EXISTS `cek_senet_kasa` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `firmaID` int(11) NOT NULL DEFAULT 0,
  `miktar` double(10,2) unsigned NOT NULL DEFAULT 0.00,
  `bas_tarih` datetime DEFAULT '0000-00-00 00:00:00',
  `cekNo` varchar(100) DEFAULT '',
  `vade_tarih` datetime DEFAULT '0000-00-00 00:00:00',
  `banka_hesapID` int(11) NOT NULL DEFAULT 0,
  `aciklama` varchar(255) DEFAULT '',
  `alindi` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `verildi` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `cek_senet` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '0:cek    1:senet',
  `tahsil_ode_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `tahsil_et` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `ode` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `iade` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `ciro_date` datetime DEFAULT NULL,
  `ciro_firmaID` int(10) unsigned NOT NULL DEFAULT 0,
  `verilen_bankaID` int(10) unsigned NOT NULL DEFAULT 0,
  `cek_notAvailable` int(10) unsigned NOT NULL DEFAULT 0,
  `tahsil_kasaID` int(10) unsigned NOT NULL DEFAULT 0,
  `tahsil_bankaID` int(10) unsigned NOT NULL DEFAULT 0,
  `ode_kasaID` int(10) unsigned NOT NULL DEFAULT 0,
  `ode_bankaID` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `index_2` (`firmaID`),
  KEY `index_3` (`iade`),
  KEY `Index_4` (`bas_tarih`),
  KEY `Index_5` (`vade_tarih`),
  KEY `Index_6` (`tahsil_ode_date`),
  KEY `Index_7` (`ciro_date`),
  KEY `Index_8` (`ciro_firmaID`)
) ENGINE=MyISAM AUTO_INCREMENT=874 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci ROW_FORMAT=FIXED;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.depobaslik
CREATE TABLE IF NOT EXISTS `depobaslik` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `faturaNo` varchar(45) NOT NULL DEFAULT '',
  `MusteriID` varchar(45) DEFAULT '0',
  `FaturaTarih` datetime DEFAULT NULL,
  `FaturaVade` datetime DEFAULT NULL,
  `UrunSayisi` int(10) unsigned NOT NULL DEFAULT 0,
  `Toplam` double(10,2) NOT NULL DEFAULT 0.00,
  `FaturaTuru` int(10) unsigned NOT NULL DEFAULT 1 COMMENT '1=Satış,2=Satış İade,3=Alış, 4=Alış İade',
  `FaturaNotlar` varchar(255) DEFAULT NULL,
  `AliciAd` varchar(145) DEFAULT NULL,
  `AliciKisaKod` varchar(145) DEFAULT NULL,
  `odemeTuru` int(10) unsigned DEFAULT 0 COMMENT '0=cari, 1=nakit, 2=kart',
  `depoFaturaID` int(10) unsigned DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `Index_2` (`MusteriID`),
  KEY `Index_3` (`FaturaTarih`),
  KEY `index_4` (`FaturaTuru`)
) ENGINE=MyISAM AUTO_INCREMENT=6887 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.depodetay
CREATE TABLE IF NOT EXISTS `depodetay` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `faturaID` double(10,0) DEFAULT NULL,
  `musteriID` double(10,0) DEFAULT NULL,
  `urunID` double(10,0) DEFAULT NULL,
  `urunEder` double(10,2) DEFAULT NULL,
  `miktar` double(10,0) DEFAULT NULL,
  `urunAD` varchar(145) DEFAULT NULL,
  `urunBarKod` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `Index_2` (`faturaID`),
  KEY `Index_3` (`musteriID`),
  KEY `Index_4` (`urunID`),
  KEY `Index_5` (`urunBarKod`),
  KEY `Index_6` (`urunAD`)
) ENGINE=MyISAM AUTO_INCREMENT=56918 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.depolar
CREATE TABLE IF NOT EXISTS `depolar` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `depo` varchar(145) DEFAULT NULL,
  `aciklama` longtext DEFAULT NULL,
  `urunSayisi` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.faturabaski
CREATE TABLE IF NOT EXISTS `faturabaski` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `faturaID` int(10) unsigned NOT NULL,
  `baskiTarihi` datetime NOT NULL,
  `kullaniciID` int(10) unsigned NOT NULL,
  `baskiNotu` varchar(245) NOT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.faturabaslik
CREATE TABLE IF NOT EXISTS `faturabaslik` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `faturaNo` varchar(45) NOT NULL DEFAULT '',
  `MusteriID` varchar(45) DEFAULT '0',
  `FaturaTarih` datetime DEFAULT NULL,
  `FaturaVade` datetime DEFAULT NULL,
  `OnToplam` double(10,2) NOT NULL DEFAULT 0.00,
  `Indirim` double(10,2) NOT NULL DEFAULT 0.00,
  `KDV` double(10,2) NOT NULL DEFAULT 0.00,
  `UrunSayisi` int(10) unsigned NOT NULL DEFAULT 0,
  `Toplam` double(10,2) NOT NULL DEFAULT 0.00,
  `FaturaTuru` int(10) unsigned NOT NULL DEFAULT 1 COMMENT '1=Satış,2=Satış İade,3=Alış, 4=Alış İade',
  `FaturaNotlar` varchar(255) DEFAULT NULL,
  `AliciAd` varchar(245) DEFAULT NULL,
  `AliciTelefon` varchar(145) DEFAULT NULL,
  `AliciAdres` varchar(245) DEFAULT NULL,
  `AliciVergiD` varchar(145) DEFAULT '',
  `AliciVergiNo` varchar(45) DEFAULT '',
  `AliciIlce` varchar(145) DEFAULT '0',
  `AliciSehir` varchar(145) DEFAULT '0',
  `AliciSemt` varchar(145) DEFAULT NULL,
  `AliciKisaKod` varchar(145) DEFAULT NULL,
  `gayriResmi` int(10) unsigned DEFAULT 0,
  `odemeYapildi` int(10) unsigned DEFAULT 0,
  `odemeTuru` int(10) unsigned DEFAULT 0 COMMENT '0=cari, 1=nakit, 2=kart',
  `odemeKasaID` int(10) unsigned DEFAULT 0,
  `odemeBankaID` int(10) unsigned DEFAULT 0,
  `depoIslem` int(10) unsigned NOT NULL DEFAULT 0,
  `netkitapSepetID` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `Index_2` (`MusteriID`),
  KEY `Index_3` (`FaturaTarih`),
  KEY `index_4` (`FaturaTuru`),
  KEY `index_5` (`odemeYapildi`),
  KEY `Index_6` (`netkitapSepetID`),
  KEY `Index_7` (`faturaNo`)
) ENGINE=MyISAM AUTO_INCREMENT=333664 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.faturadetay
CREATE TABLE IF NOT EXISTS `faturadetay` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `faturaID` int(10) unsigned DEFAULT NULL,
  `musteriID` int(10) unsigned DEFAULT NULL,
  `urunID` int(10) unsigned DEFAULT NULL,
  `urunEder` double(10,2) DEFAULT NULL,
  `miktar` int(10) unsigned DEFAULT NULL,
  `urunKDV` int(10) unsigned DEFAULT NULL,
  `urunIndirim` int(10) unsigned DEFAULT NULL,
  `urunKDVsizToplam` double(10,2) DEFAULT NULL,
  `urunAD` varchar(255) DEFAULT NULL,
  `urunBarKod` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `Index_2` (`faturaID`),
  KEY `Index_3` (`musteriID`),
  KEY `Index_4` (`urunID`),
  KEY `Index_5` (`urunBarKod`),
  KEY `Index_6` (`urunAD`)
) ENGINE=MyISAM AUTO_INCREMENT=3559912 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.faturaturleri
CREATE TABLE IF NOT EXISTS `faturaturleri` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `FaturaTuru` varchar(45) CHARACTER SET latin5 COLLATE latin5_turkish_ci NOT NULL,
  `islemYon` int(10) unsigned NOT NULL DEFAULT 1 COMMENT '1=stok artı,2=stok eksi,0=stok hareketi yok',
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_uca1400_ai_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.hesaplar
CREATE TABLE IF NOT EXISTS `hesaplar` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `banka_hesapad` varchar(145) NOT NULL,
  `subeAd` varchar(45) DEFAULT NULL,
  `subeNo` varchar(45) DEFAULT NULL,
  `hesapno` varchar(45) DEFAULT NULL,
  `krediKartiKomisyon` double(10,2) DEFAULT NULL,
  `puanKomisyon` double(10,2) DEFAULT NULL,
  `komsiyonsuzGunBirakma` double(10,2) DEFAULT NULL,
  `notlar` varchar(245) DEFAULT NULL,
  `hesapturu` varchar(20) NOT NULL DEFAULT 'Diğer',
  `bakiye` double(10,2) NOT NULL DEFAULT 0.00,
  `kurID` varchar(5) NOT NULL DEFAULT '1',
  `brokeGun` int(11) NOT NULL DEFAULT 0,
  `tur` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=17 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.ilceler
CREATE TABLE IF NOT EXISTS `ilceler` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `ilce` varchar(50) DEFAULT NULL,
  `sehirID` int(11) DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=986 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.kasa
CREATE TABLE IF NOT EXISTS `kasa` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `MusteriID` int(10) unsigned NOT NULL,
  `islemTarihi` datetime NOT NULL,
  `Miktar` double(10,2) NOT NULL,
  `Islem` int(10) unsigned NOT NULL,
  `KurID` int(10) unsigned DEFAULT NULL,
  `Aciklama` longtext DEFAULT NULL,
  `CaridenGizle` int(10) unsigned NOT NULL DEFAULT 0,
  `KategoriID` int(10) unsigned NOT NULL DEFAULT 0,
  `BelgeNo` varchar(45) DEFAULT NULL,
  `BankaIslemID` int(10) unsigned NOT NULL DEFAULT 0,
  `FaturaID` int(10) unsigned NOT NULL DEFAULT 0,
  `giderID` int(10) unsigned NOT NULL DEFAULT 0,
  `gelirID` int(10) unsigned NOT NULL DEFAULT 0,
  `ceksenetID` int(10) unsigned NOT NULL DEFAULT 0,
  `UrunID` int(10) unsigned NOT NULL DEFAULT 0,
  `urunAdet` int(10) unsigned NOT NULL DEFAULT 0,
  `urunAdi` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `index_2` (`MusteriID`),
  KEY `index_3` (`islemTarihi`),
  KEY `index_4` (`KategoriID`),
  KEY `index_5` (`BankaIslemID`),
  KEY `index_6` (`CaridenGizle`),
  KEY `Index_7` (`UrunID`)
) ENGINE=MyISAM AUTO_INCREMENT=27391 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.kategoriler
CREATE TABLE IF NOT EXISTS `kategoriler` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kategori` varchar(145) DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=31 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.kurhareketleri
CREATE TABLE IF NOT EXISTS `kurhareketleri` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kurID` int(10) unsigned NOT NULL DEFAULT 1,
  `kurDeger` double(10,2) NOT NULL DEFAULT 1.00,
  `Tarih` date DEFAULT NULL,
  PRIMARY KEY (`ID`,`kurDeger`),
  KEY `Index_2` (`Tarih`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.kurlar
CREATE TABLE IF NOT EXISTS `kurlar` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kur` varchar(45) NOT NULL DEFAULT 'YTL',
  `YTLOran` double(10,2) DEFAULT 1.00,
  `SonGuncelleme` datetime DEFAULT NULL,
  `kurUzunAd` varchar(145) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `Index_1` (`kur`) USING BTREE
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.odemefatura
CREATE TABLE IF NOT EXISTS `odemefatura` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `faturaID` varchar(10) NOT NULL,
  `odemeID` varchar(10) NOT NULL,
  `tutar` double(10,2) NOT NULL,
  `kurID` varchar(10) NOT NULL,
  `odenen` double(10,2) NOT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=44 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.odemeler
CREATE TABLE IF NOT EXISTS `odemeler` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `musteriID` varchar(10) NOT NULL DEFAULT '1',
  `turu` varchar(10) NOT NULL DEFAULT '1' COMMENT 'nakit,çek,kredi',
  `tarih` date NOT NULL,
  `vadetarih` date NOT NULL,
  `tutar` double(10,2) NOT NULL DEFAULT 0.00,
  `kurID` varchar(10) NOT NULL DEFAULT '1',
  `Taksit` int(10) unsigned NOT NULL DEFAULT 0,
  `HesapID` int(10) unsigned NOT NULL DEFAULT 1,
  `Aciklama` varchar(512) DEFAULT NULL,
  `parasalIslem` varchar(45) NOT NULL DEFAULT 'nakit',
  `odendi` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=54 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.sehirler
CREATE TABLE IF NOT EXISTS `sehirler` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `sehir` varchar(50) DEFAULT NULL,
  `ulkeID` int(11) DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=83 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.ulkeler
CREATE TABLE IF NOT EXISTS `ulkeler` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `ulke` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=221 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.ureticiler
CREATE TABLE IF NOT EXISTS `ureticiler` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uretici` varchar(145) DEFAULT NULL,
  `adres` varchar(245) DEFAULT NULL,
  `telefon` varchar(145) DEFAULT NULL,
  `aciklama` longtext DEFAULT NULL,
  `TedarikciIDler` longtext DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `Index_2` (`uretici`),
  FULLTEXT KEY `Index_3` (`TedarikciIDler`)
) ENGINE=MyISAM AUTO_INCREMENT=5184 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

-- Dumping structure for table cinius_nakitakis.urungruplari
CREATE TABLE IF NOT EXISTS `urungruplari` (
  `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grup` varchar(145) DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=7 DEFAULT CHARSET=latin5 COLLATE=latin5_turkish_ci;

-- Data exporting was unselected.

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
