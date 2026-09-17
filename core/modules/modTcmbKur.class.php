<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * TCMB (Türkiye Cumhuriyet Merkez Bankası) exchange rates for Dolibarr multicurrency.
 *
 * Reads the daily bulletin (today.xml / archive), writes rates for the selected currencies
 * into Dolibarr's own multicurrency tables through the core CurrencyRate class, with the
 * bulletin date (or the next business day, as Turkish accounting practice requires).
 */
class modTcmbKur extends DolibarrModules
{
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;
		$this->numero = 194093; // reserved on wiki.dolibarr.org List_of_modules_id (M. Burak Şentürk: 194091-194100)
		$this->rights_class = 'tcmbkur';
		$this->family = 'financial';
		$this->module_position = '92';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'TCMB döviz kurları: Merkez Bankası günlük bültenini Dolibarr çoklu para birimi kurlarına aktarır';
		$this->descriptionlong = 'Seçilen para birimleri için TCMB bülten kurunu (alış/satış/efektif/ortalama) bülten tarihi ya da ertesi iş günü ile Dolibarr kur tablosuna yazar; geçmiş günleri doldurur, tatilde son bültene düşer, TRY dışı ana para birimi için çapraz kur hesaplar.';
		$this->editor_name = 'M. Burak Şentürk';
		$this->editor_url = 'https://buraksenturk.net';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'multicurrency';

		$this->module_parts = array('hooks' => array('aimcp'));
		$this->dirs = array();
		$this->config_page_url = array('setup.php@tcmbkur');
		$this->depends = array('modMultiCurrency');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('tcmbkur@tcmbkur');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(20, 0);

		$this->const = array(
			array('TCMBKUR_CURRENCIES', 'chaine', 'USD,EUR,GBP', 'Aktarılacak para birimleri (virgülle)', 0, 'current', 0),
			array('TCMBKUR_RATE_TYPE', 'chaine', 'ForexBuying', 'ForexBuying | ForexSelling | BanknoteBuying | BanknoteSelling | ForexAverage', 0, 'current', 0),
			array('TCMBKUR_APPLY_NEXT_BUSINESS_DAY', 'chaine', '1', 'Kur, bülten tarihinin ertesi iş günü ile kaydedilsin (VUK uygulaması)', 0, 'current', 0),
			array('TCMBKUR_ADD_MISSING_CURRENCY', 'chaine', '1', 'Dolibarr\'da tanımlı olmayan para birimini otomatik ekle', 0, 'current', 0),
			array('TCMBKUR_SKIP_EXISTING', 'chaine', '1', 'Aynı tarihte kur varsa tekrar yazma', 0, 'current', 0),
			array('TCMBKUR_CROSS_RATES', 'chaine', '1', 'Ana para birimi TRY değilse TCMB üzerinden çapraz kur hesapla', 0, 'current', 0),
			array('TCMBKUR_BACKFILL_DAYS', 'chaine', '30', 'Geçmişi doldur varsayılan gün sayısı', 0, 'current', 0),
			array('TCMBKUR_HOLIDAY_LOOKBACK', 'chaine', '10', 'Bülten yoksa (tatil) geriye bakılacak gün', 0, 'current', 0),
			array('TCMBKUR_TIMEOUT', 'chaine', '20', 'HTTP zaman aşımı (sn)', 0, 'current', 0),
			array('TCMBKUR_LOG_KEEP_DAYS', 'chaine', '90', 'Çalışma günlüğü saklama süresi (gün)', 0, 'current', 0),
		);

		if (!isModEnabled('tcmbkur')) {
			$conf->tcmbkur = new stdClass();
			$conf->tcmbkur->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		$this->cronjobs = array(
			array(
				'label' => 'TcmbKurDailySync',
				'jobtype' => 'method',
				'class' => '/tcmbkur/class/tcmbkur.class.php',
				'objectname' => 'TcmbKur',
				'method' => 'cronSync',
				'parameters' => '',
				'comment' => 'TCMB günlük bültenini Dolibarr kurlarına aktarır (bülten 15:30 sonrası yayınlanır; görevi 16:00 sonrasına ayarlayın)',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'test' => 'isModEnabled("tcmbkur")',
				'priority' => 50,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero * 10 + 1;
		$this->rights[$r][1] = 'TCMB kurlarını görüntüle';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero * 10 + 2;
		$this->rights[$r][1] = 'TCMB kurlarını güncelle';
		$this->rights[$r][4] = 'sync';

		$this->menu = array();
	}

	public function init($options = '')
	{
		$this->cleanupLegacy();
		$result = $this->_load_tables('/tcmbkur/sql/');
		if ($result < 0) {
			return -1;
		}
		return $this->_init(array(), $options);
	}

	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}

	/**
	 * Versions before 1.0 used an unreserved module number and a differently named cron job.
	 */
	private function cleanupLegacy()
	{
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."cronjob WHERE module_name = 'tcmbkur' AND label = 'TCMB USD/EUR kurlarini guncelle'");
		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."cronjob WHERE classesname = '/tcmbkur/class/tcmbkur.class.php' AND objectname = 'TcmbKurService'");
	}
}
