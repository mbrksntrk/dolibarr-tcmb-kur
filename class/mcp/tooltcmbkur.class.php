<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

require_once DOL_DOCUMENT_ROOT.'/ai/class/mcptool.class.php';
dol_include_once('/tcmbkur/class/tcmbkur.class.php');

/**
 * MCP tools of the TCMB rates module (Dolibarr AI module, hook 'addMcpTools').
 */
class ToolTcmbKur extends McpTool
{
	public function getCategories(): array
	{
		return ['billing', 'reporting'];
	}

	public function getDefinitions(): array
	{
		return [
			[
				"name" => "tcmb_rates",
				"description" => "Official TCMB (Central Bank of Türkiye) exchange rates for a date: buying/selling/banknote rates in TRY per unit for every currency in the bulletin, plus the rate Dolibarr has stored for the module's selected currencies. Use for 'what is today's USD rate?', 'EUR rate on 2026-09-15?'. Read-only; fetches the bulletin live.",
				"inputSchema" => [
					"type" => "object",
					"properties" => [
						"date" => ["type" => "string", "description" => "Bulletin date YYYY-MM-DD. Omit for the latest bulletin. Weekends/holidays fall back to the previous bulletin."],
						"currencies" => ["type" => "string", "description" => "Comma-separated ISO codes to return (e.g. USD,EUR). Omit for the module's configured currencies; 'all' for the whole bulletin."],
					],
				],
			],
			[
				"name" => "tcmb_sync",
				"description" => "Write the latest TCMB bulletin (or a past date's bulletin) into Dolibarr multicurrency rates for the configured currencies, following the module settings (rate type, next-business-day dating, skip existing). Ask the user for confirmation before calling.",
				"inputSchema" => [
					"type" => "object",
					"properties" => [
						"date" => ["type" => "string", "description" => "Bulletin date YYYY-MM-DD; omit for the latest."],
						"backfill_days" => ["type" => "integer", "description" => "Instead of one bulletin, fill every business day of the last N days that has no stored rate."],
					],
				],
			],
		];
	}

	public function execute(string $toolName, array $args)
	{
		if (!isModEnabled('tcmbkur')) {
			return ["error" => "TCMB rates module is not enabled."];
		}
		if (!$this->user->hasRight('tcmbkur', 'read')) {
			return ["error" => "Permission denied: tcmbkur read"];
		}
		$svc = new TcmbKur($this->db);
		switch ($toolName) {
			case 'tcmb_rates':
				return $this->rates($svc, $args);
			case 'tcmb_sync':
				return $this->sync($svc, $args);
		}
		return ["error" => "Tool function '$toolName' not found."];
	}

	private function parseDate($s)
	{
		$s = trim((string) $s);
		if ($s === '') {
			return null;
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !strtotime($s)) {
			return false;
		}
		return $s;
	}

	private function rates(TcmbKur $svc, array $args)
	{
		$date = $this->parseDate($args['date'] ?? '');
		if ($date === false) {
			return ["error" => "date must be YYYY-MM-DD"];
		}
		$b = $svc->fetchBulletin($date);
		if ($b === null) {
			return ["error" => $svc->error];
		}
		$want = strtoupper(trim((string) ($args['currencies'] ?? '')));
		$codes = $want === 'ALL' ? array_keys($b['rates']) : ($want !== '' ? preg_split('/[\s,;]+/', $want) : TcmbKur::currencies());
		$type = TcmbKur::rateType();
		$rows = [];
		foreach ($codes as $c) {
			if (!isset($b['rates'][$c])) {
				$rows[] = ["code" => $c, "error" => "not in bulletin"];
				continue;
			}
			$r = $b['rates'][$c];
			$conv = $svc->convert($b, $c);
			$stored = $svc->storedRates($c, 1);
			$rows[] = [
				"code" => $c, "name" => $r['name'], "unit" => $r['unit'],
				"forex_buying" => $r['ForexBuying'], "forex_selling" => $r['ForexSelling'],
				"banknote_buying" => $r['BanknoteBuying'], "banknote_selling" => $r['BanknoteSelling'],
				"selected_type" => $type, "try_per_unit_selected" => $r[$type],
				"dolibarr_rate" => $conv ? round($conv['rate'], 8) : null,
				"dolibarr_stored" => $stored ? $stored[0] : null,
			];
		}
		return [
			"bulletin_date" => $b['date'], "bulletin_no" => $b['no'], "source" => $b['source'],
			"rate_date_in_dolibarr" => TcmbKur::rateDateFor($b['date']),
			"main_currency" => TcmbKur::mainCurrency(),
			"note" => "Values are TRY per 1 unit of the currency. dolibarr_rate = foreign per 1 main currency (Dolibarr convention).",
			"rates" => $rows,
		];
	}

	private function sync(TcmbKur $svc, array $args)
	{
		if (!$this->user->hasRight('tcmbkur', 'sync')) {
			return ["error" => "Permission denied: tcmbkur sync"];
		}
		if (!isModEnabled('multicurrency')) {
			return ["error" => "Multicurrency module is not enabled."];
		}
		$days = (int) ($args['backfill_days'] ?? 0);
		if ($days > 0) {
			$r = $svc->backfill($days, $this->user);
			return ["success" => empty($r['errors']) || $r['written'] > 0, "summary" => $svc->output] + $r;
		}
		$date = $this->parseDate($args['date'] ?? '');
		if ($date === false) {
			return ["error" => "date must be YYYY-MM-DD"];
		}
		$b = $svc->fetchBulletin($date);
		if ($b === null) {
			return ["error" => $svc->error];
		}
		$r = $svc->apply($b, 'mcp', $this->user);
		return ["success" => $r['written'] > 0 || empty($r['errors']), "summary" => $svc->summary($b, $r)] + $r;
	}
}
