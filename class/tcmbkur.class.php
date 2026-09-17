<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

/**
 * TCMB bulletin → Dolibarr multicurrency.
 *
 * Rates are written through the core CurrencyRate class (never by direct SQL on the
 * multicurrency tables). Dolibarr convention: `rate` = units of foreign currency per 1 unit
 * of the main currency, `rate_direct` = main currency per 1 unit of foreign.
 *
 * TCMB publishes "Gösterge Niteliğindeki Kurlar" every business day around 15:30; the bulletin
 * dated D is, by Turkish tax practice (VUK 280), the rate used for transactions on the next
 * business day. Setting TCMBKUR_APPLY_NEXT_BUSINESS_DAY controls which date is stored.
 */
class TcmbKur
{
	const URL_TODAY = 'https://www.tcmb.gov.tr/kurlar/today.xml';
	const URL_ARCHIVE = 'https://www.tcmb.gov.tr/kurlar/%s/%s.xml'; // YYYYMM / DDMMYYYY

	const RATE_TYPES = array('ForexBuying', 'ForexSelling', 'BanknoteBuying', 'BanknoteSelling', 'ForexAverage');

	public $db;
	public $error = '';
	public $errors = array();
	/** @var string cron output */
	public $output = '';

	private $bulletinCache = array();

	public function __construct($db)
	{
		$this->db = $db;
	}

	// ------------------------------------------------------------------ settings

	public static function currencies()
	{
		$list = array();
		foreach (preg_split('/[\s,;]+/', strtoupper(getDolGlobalString('TCMBKUR_CURRENCIES', 'USD,EUR,GBP'))) as $c) {
			if (preg_match('/^[A-Z]{3}$/', $c) && $c !== 'TRY') {
				$list[$c] = $c;
			}
		}
		return array_values($list);
	}

	public static function rateType()
	{
		$t = getDolGlobalString('TCMBKUR_RATE_TYPE', 'ForexBuying');
		return in_array($t, self::RATE_TYPES, true) ? $t : 'ForexBuying';
	}

	public static function mainCurrency()
	{
		global $conf;
		return strtoupper((string) $conf->currency);
	}

	// ------------------------------------------------------------------ TCMB bulletin

	/**
	 * Fetch and parse a bulletin.
	 *
	 * @param  string|null $date  'Y-m-d' bulletin date, null = today.xml (latest published)
	 * @param  bool        $fallback  Walk back to the previous published bulletin on holidays
	 * @return array|null  array('date' => 'Y-m-d', 'no' => '2026/175', 'source' => url, 'rates' => [code => [...]])
	 */
	public function fetchBulletin($date = null, $fallback = true)
	{
		$this->error = '';
		$lookback = max(0, getDolGlobalInt('TCMBKUR_HOLIDAY_LOOKBACK', 10));
		$tries = $date === null ? 1 : ($fallback ? $lookback + 1 : 1);
		$cursor = $date;
		for ($i = 0; $i < $tries; $i++) {
			$key = $cursor === null ? 'today' : $cursor;
			if (isset($this->bulletinCache[$key])) {
				return $this->bulletinCache[$key];
			}
			$url = $cursor === null ? self::URL_TODAY : sprintf(self::URL_ARCHIVE, date('Ym', strtotime($cursor)), date('dmY', strtotime($cursor)));
			$xml = $this->download($url);
			if ($xml !== null) {
				$b = $this->parse($xml, $url);
				if ($b !== null) {
					$this->bulletinCache[$key] = $b;
					return $b;
				}
			}
			if ($cursor === null) {
				break;
			}
			// holiday / weekend: previous calendar day
			$cursor = date('Y-m-d', strtotime($cursor) - 86400);
		}
		if ($this->error === '') {
			$this->error = 'TCMB bulletin not available'.($date ? ' for '.$date : '');
		}
		return null;
	}

	private function download($url)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
		$timeout = max(5, getDolGlobalInt('TCMBKUR_TIMEOUT', 20));
		$res = getURLContent($url, 'GET', '', 1, array(), array('https'), 0, -1, $timeout, $timeout);
		$code = (int) ($res['http_code'] ?? 0);
		if ($code === 404) {
			return null; // no bulletin that day
		}
		if ($code < 200 || $code >= 300 || empty($res['content'])) {
			$this->error = 'TCMB HTTP '.$code.(!empty($res['curl_error_msg']) ? ' '.$res['curl_error_msg'] : '').' ('.$url.')';
			dol_syslog('TcmbKur::download '.$this->error, LOG_WARNING);
			return null;
		}
		return $res['content'];
	}

	private function parse($content, $url)
	{
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
		if ($xml === false || !isset($xml->Currency)) {
			$this->error = 'TCMB XML could not be parsed ('.$url.')';
			return null;
		}
		$rawDate = trim((string) $xml['Date']); // 09/17/2026
		$ts = $rawDate ? strtotime(str_replace('/', '-', preg_replace('#^(\d{2})/(\d{2})/(\d{4})$#', '$3-$1-$2', $rawDate))) : false;
		if (!$ts) {
			$this->error = 'TCMB bulletin date missing ('.$url.')';
			return null;
		}
		$rates = array();
		foreach ($xml->Currency as $c) {
			$code = strtoupper(trim((string) $c['CurrencyCode']));
			if ($code === '') {
				continue;
			}
			$unit = max(1, (int) $c->Unit);
			$row = array('code' => $code, 'unit' => $unit, 'name' => trim((string) $c->CurrencyName), 'name_tr' => trim((string) $c->Isim));
			foreach (array('ForexBuying', 'ForexSelling', 'BanknoteBuying', 'BanknoteSelling') as $t) {
				$v = (float) str_replace(',', '.', trim((string) $c->$t));
				$row[$t] = $v > 0 ? $v / $unit : null; // TRY per 1 unit
			}
			$row['ForexAverage'] = ($row['ForexBuying'] && $row['ForexSelling']) ? ($row['ForexBuying'] + $row['ForexSelling']) / 2 : null;
			$rates[$code] = $row;
		}
		return array('date' => date('Y-m-d', $ts), 'no' => trim((string) $xml['Bulten_No']), 'source' => $url, 'rates' => $rates);
	}

	// ------------------------------------------------------------------ conversion

	/**
	 * Dolibarr rate pair for a currency from a bulletin, relative to the main currency.
	 *
	 * @return array|null array('rate' => foreign per 1 main, 'rate_direct' => main per 1 foreign, 'try_per_unit' => float)
	 */
	public function convert(array $bulletin, $code, $type = null)
	{
		$type = $type ?: self::rateType();
		$main = self::mainCurrency();
		$code = strtoupper($code);
		if ($code === $main) {
			return null;
		}
		$tryPer = function ($c) use ($bulletin, $type) {
			if ($c === 'TRY') {
				return 1.0;
			}
			return isset($bulletin['rates'][$c][$type]) && $bulletin['rates'][$c][$type] > 0 ? (float) $bulletin['rates'][$c][$type] : null;
		};
		$tryPerForeign = $tryPer($code);
		if ($tryPerForeign === null) {
			$this->error = $code.' not in bulletin '.$bulletin['date'];
			return null;
		}
		if ($main === 'TRY') {
			return array('rate' => 1 / $tryPerForeign, 'rate_direct' => $tryPerForeign, 'try_per_unit' => $tryPerForeign);
		}
		if (!getDolGlobalInt('TCMBKUR_CROSS_RATES', 1)) {
			$this->error = 'Main currency is '.$main.'; enable cross rates in setup';
			return null;
		}
		$tryPerMain = $tryPer($main);
		if ($tryPerMain === null) {
			$this->error = 'Main currency '.$main.' not in bulletin '.$bulletin['date'];
			return null;
		}
		// 1 main = tryPerMain TRY; 1 foreign = tryPerForeign TRY → foreign per main = tryPerMain / tryPerForeign
		return array('rate' => $tryPerMain / $tryPerForeign, 'rate_direct' => $tryPerForeign / $tryPerMain, 'try_per_unit' => $tryPerForeign);
	}

	/** Date to store for a bulletin, honouring the "next business day" setting. */
	public static function rateDateFor($bulletinDate)
	{
		if (!getDolGlobalInt('TCMBKUR_APPLY_NEXT_BUSINESS_DAY', 1)) {
			return $bulletinDate;
		}
		$ts = strtotime($bulletinDate) + 86400;
		while (in_array((int) date('N', $ts), array(6, 7), true)) {
			$ts += 86400;
		}
		return date('Y-m-d', $ts);
	}

	// ------------------------------------------------------------------ Dolibarr rates

	private function multicurrency($code, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
		$mc = new MultiCurrency($this->db);
		if ($mc->fetch(0, $code) > 0) {
			return $mc;
		}
		if (!getDolGlobalInt('TCMBKUR_ADD_MISSING_CURRENCY', 1)) {
			$this->error = $code.' is not defined in Dolibarr multicurrency (enable auto-add in setup)';
			return null;
		}
		$mc = new MultiCurrency($this->db);
		$mc->code = $code;
		$mc->name = $code;
		$res = $this->db->query("SELECT label FROM ".MAIN_DB_PREFIX."c_currencies WHERE code_iso = '".$this->db->escape($code)."'");
		if ($res && ($o = $this->db->fetch_object($res))) {
			$mc->name = $o->label;
		}
		if ($mc->create($user) <= 0) {
			$this->error = 'Cannot create currency '.$code.': '.$mc->error;
			return null;
		}
		return $mc;
	}

	private function rateExists($fkMulticurrency, $date)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."multicurrency_rate WHERE fk_multicurrency = ".((int) $fkMulticurrency);
		$sql .= " AND entity IN (".getEntity('multicurrency').") AND DATE(date_sync) = '".$this->db->escape($date)."' LIMIT 1";
		$res = $this->db->query($sql);
		return $res && $this->db->num_rows($res) > 0;
	}

	/**
	 * Write one bulletin into Dolibarr for the configured currencies.
	 *
	 * @param  array  $bulletin  from fetchBulletin()
	 * @param  string $mode      sync | backfill | cron | mcp (log)
	 * @return array{written:int,skipped:int,errors:string[],rate_date:string,lines:array}
	 */
	public function apply(array $bulletin, $mode, $user = null)
	{
		require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/currencyrate.class.php';
		global $conf;
		$rateDate = self::rateDateFor($bulletin['date']);
		$out = array('written' => 0, 'skipped' => 0, 'errors' => array(), 'rate_date' => $rateDate, 'bulletin_date' => $bulletin['date'], 'lines' => array());
		$skipExisting = getDolGlobalInt('TCMBKUR_SKIP_EXISTING', 1);
		$u = $user ?: $this->cronUser();

		$codes = self::currencies();
		if (self::mainCurrency() !== 'TRY' && getDolGlobalInt('TCMBKUR_CROSS_RATES', 1)) {
			$codes[] = 'TRY'; // the lira itself becomes a foreign currency
		}
		foreach ($codes as $code) {
			$conv = $this->convert($bulletin, $code);
			if ($conv === null) {
				$out['errors'][] = $this->error;
				continue;
			}
			$mc = $this->multicurrency($code, $u);
			if ($mc === null) {
				$out['errors'][] = $this->error;
				continue;
			}
			if ($skipExisting && $this->rateExists($mc->id, $rateDate)) {
				$out['skipped']++;
				$out['lines'][] = array('code' => $code, 'status' => 'skip', 'try_per_unit' => $conv['try_per_unit'], 'rate' => $conv['rate']);
				continue;
			}
			$cr = new CurrencyRate($this->db);
			$cr->rate = (float) price2num($conv['rate'], 8);
			$cr->rate_direct = (float) price2num($conv['rate_direct'], 8);
			$cr->date_sync = strtotime($rateDate.' 12:00:00');
			$cr->entity = $conf->entity;
			if ($cr->create($u, (int) $mc->id) <= 0) {
				$out['errors'][] = $code.': '.implode(', ', $cr->errors ?: array($cr->error));
				continue;
			}
			$out['written']++;
			$out['lines'][] = array('code' => $code, 'status' => 'ok', 'try_per_unit' => $conv['try_per_unit'], 'rate' => $conv['rate']);
		}

		$this->log($mode, $bulletin, $rateDate, $out, $u);
		return $out;
	}

	/**
	 * Latest bulletin → Dolibarr.
	 * @return int >0 ok, 0 nothing new, <0 error
	 */
	public function sync($user = null, $mode = 'sync')
	{
		$b = $this->fetchBulletin(null);
		if ($b === null) {
			$this->log($mode, null, null, array('written' => 0, 'skipped' => 0, 'errors' => array($this->error)), $user ?: $this->cronUser());
			return -1;
		}
		$r = $this->apply($b, $mode, $user);
		$this->output = $this->summary($b, $r);
		if ($r['errors'] && !$r['written']) {
			$this->error = implode('; ', $r['errors']);
			return -1;
		}
		return $r['written'] > 0 ? 1 : 0;
	}

	/**
	 * Fill missing days: for each calendar day in the window take that day's bulletin (skip days without one).
	 * @return array{days:int,written:int,skipped:int,errors:string[]}
	 */
	public function backfill($days, $user = null)
	{
		$days = max(1, min(400, (int) $days));
		$tot = array('days' => 0, 'written' => 0, 'skipped' => 0, 'errors' => array());
		$today = dol_now();
		for ($i = $days; $i >= 0; $i--) {
			$d = date('Y-m-d', $today - $i * 86400);
			if (in_array((int) date('N', $today - $i * 86400), array(6, 7), true)) {
				continue;
			}
			$b = $this->fetchBulletin($d, false);
			if ($b === null) {
				continue; // holiday
			}
			$tot['days']++;
			$r = $this->apply($b, 'backfill', $user);
			$tot['written'] += $r['written'];
			$tot['skipped'] += $r['skipped'];
			$tot['errors'] = array_merge($tot['errors'], $r['errors']);
		}
		$this->output = sprintf('%d bulletins, %d rates written, %d skipped%s', $tot['days'], $tot['written'], $tot['skipped'], $tot['errors'] ? ', errors: '.implode('; ', array_unique($tot['errors'])) : '');
		return $tot;
	}

	/** Dolibarr cron entry point: 0 = OK. */
	public function cronSync()
	{
		if (!isModEnabled('multicurrency')) {
			$this->error = 'Multicurrency module is not enabled';
			$this->output = $this->error;
			return -1;
		}
		$r = $this->sync(null, 'cron');
		$this->purgeLog();
		return $r < 0 ? -1 : 0;
	}

	// ------------------------------------------------------------------ reporting

	public function summary(array $b, array $r)
	{
		$parts = array();
		foreach ($r['lines'] as $l) {
			$parts[] = $l['code'].' '.price($l['try_per_unit'], 0, '', 1, 4, 4).($l['status'] === 'skip' ? ' (skip)' : '');
		}
		return sprintf('TCMB %s (%s) → %s: %d written, %d skipped%s%s', $b['date'], $b['no'], $r['rate_date'], $r['written'], $r['skipped'], $parts ? ' | '.implode(', ', $parts) : '', $r['errors'] ? ' | errors: '.implode('; ', $r['errors']) : '');
	}

	/** Stored Dolibarr rates for a currency, newest first. */
	public function storedRates($code, $limit = 10)
	{
		$sql = "SELECT cr.date_sync, cr.rate, cr.rate_direct FROM ".MAIN_DB_PREFIX."multicurrency_rate cr";
		$sql .= " JOIN ".MAIN_DB_PREFIX."multicurrency m ON m.rowid = cr.fk_multicurrency";
		$sql .= " WHERE m.code = '".$this->db->escape(strtoupper($code))."' AND cr.entity IN (".getEntity('multicurrency').")";
		$sql .= " ORDER BY cr.date_sync DESC LIMIT ".((int) $limit);
		$out = array();
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = array('date' => substr($o->date_sync, 0, 10), 'rate' => (float) $o->rate, 'rate_direct' => (float) $o->rate_direct);
		}
		return $out;
	}

	public function recentLogs($limit = 20)
	{
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."tcmbkur_log WHERE entity IN (".getEntity('multicurrency').") ORDER BY rowid DESC LIMIT ".((int) $limit);
		$out = array();
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = $o;
		}
		return $out;
	}

	private function log($mode, $bulletin, $rateDate, array $r, $user)
	{
		global $conf;
		$status = $r['errors'] ? ($r['written'] ? 'ok' : 'error') : ($r['written'] ? 'ok' : 'skip');
		$detail = array();
		foreach ($r['lines'] ?? array() as $l) {
			$detail[] = $l['code'].' '.round($l['try_per_unit'], 4).' TRY → rate '.round($l['rate'], 8).($l['status'] === 'skip' ? ' (exists)' : '');
		}
		foreach ($r['errors'] as $e) {
			$detail[] = 'ERR '.$e;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."tcmbkur_log (entity, date_run, mode, bulletin_date, bulletin_no, rate_date, status, written, skipped, detail, fk_user) VALUES (";
		$sql .= ((int) $conf->entity).", '".$this->db->idate(dol_now())."', '".$this->db->escape($mode)."', ";
		$sql .= ($bulletin ? "'".$this->db->escape($bulletin['date'])."'" : 'NULL').", ".($bulletin ? "'".$this->db->escape($bulletin['no'])."'" : 'NULL').", ";
		$sql .= ($rateDate ? "'".$this->db->escape($rateDate)."'" : 'NULL').", '".$status."', ".((int) $r['written']).", ".((int) $r['skipped']).", ";
		$sql .= "'".$this->db->escape(implode("\n", $detail))."', ".($user && $user->id ? (int) $user->id : 'NULL').")";
		$this->db->query($sql);
		dol_syslog('TcmbKur '.$mode.' '.$status.' '.implode(' | ', $detail), $status === 'error' ? LOG_WARNING : LOG_INFO);
	}

	private function purgeLog()
	{
		$keep = getDolGlobalInt('TCMBKUR_LOG_KEEP_DAYS', 90);
		if ($keep > 0) {
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."tcmbkur_log WHERE date_run < '".$this->db->idate(dol_now() - $keep * 86400)."'");
		}
	}

	private function cronUser()
	{
		global $user;
		if (is_object($user) && $user->id > 0) {
			return $user;
		}
		$u = new User($this->db);
		$u->fetch(1);
		return $u;
	}
}
