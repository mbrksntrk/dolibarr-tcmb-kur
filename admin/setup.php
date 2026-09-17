<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/events.class.php';
dol_include_once('/tcmbkur/class/tcmbkur.class.php');

$langs->loadLangs(array('admin', 'multicurrency', 'tcmbkur@tcmbkur'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$svc = new TcmbKur($db);

$rateTypeOptions = array();
foreach (TcmbKur::RATE_TYPES as $t) {
	$rateTypeOptions[$t] = $langs->trans('TcmbKurType'.$t);
}

$sections = array(
	'TcmbKurSectionScope' => array(
		'TCMBKUR_CURRENCIES' => array('type' => 'text', 'size' => 40),
		'TCMBKUR_RATE_TYPE' => array('type' => 'select', 'options' => $rateTypeOptions),
		'TCMBKUR_APPLY_NEXT_BUSINESS_DAY' => array('type' => 'yesno'),
		'TCMBKUR_CROSS_RATES' => array('type' => 'yesno'),
	),
	'TcmbKurSectionBehaviour' => array(
		'TCMBKUR_ADD_MISSING_CURRENCY' => array('type' => 'yesno'),
		'TCMBKUR_SKIP_EXISTING' => array('type' => 'yesno'),
		'TCMBKUR_BACKFILL_DAYS' => array('type' => 'int', 'min' => 1),
		'TCMBKUR_HOLIDAY_LOOKBACK' => array('type' => 'int', 'min' => 0),
		'TCMBKUR_TIMEOUT' => array('type' => 'int', 'min' => 5),
		'TCMBKUR_LOG_KEEP_DAYS' => array('type' => 'int', 'min' => 0),
	),
);
$fields = array();
foreach ($sections as $s) {
	$fields += $s;
}

/* ------------------------------------------------------------------ actions */

if ($action === 'update') {
	$error = 0;
	$changed = array();
	$db->begin();
	foreach ($fields as $code => $def) {
		if ($def['type'] === 'yesno') {
			$val = GETPOST($code, 'int') ? '1' : '0';
		} elseif ($def['type'] === 'int') {
			$val = (string) max($def['min'], (int) GETPOST($code, 'int'));
		} else {
			$val = trim(GETPOST($code, 'alphanohtml'));
		}
		if ($code === 'TCMBKUR_CURRENCIES') {
			$val = implode(',', array_unique(array_filter(array_map('trim', explode(',', strtoupper(preg_replace('/[\s;]+/', ',', $val)))))));
		}
		if (getDolGlobalString($code) !== $val) {
			$changed[] = $code.': "'.getDolGlobalString($code).'" -> "'.$val.'"';
		}
		if (dolibarr_set_const($db, $code, $val, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	if ($error) {
		$db->rollback();
		setEventMessages($langs->trans('Error'), null, 'errors');
	} else {
		$db->commit();
		if ($changed) {
			$e = new Events($db);
			$e->type = 'TCMBKUR_SETUP';
			$e->dateevent = dol_now();
			$e->label = dol_trunc('TCMB rates settings changed: '.implode('; ', $changed), 250, 'right', 'UTF-8', 1);
			$e->description = 'TCMB rates settings changed: '.implode('; ', $changed);
			$e->user_agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
			$e->ip = getUserRemoteIP();
			$e->create($user);
		}
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'sync') {
	$r = $svc->sync($user, 'sync');
	if ($r > 0) {
		setEventMessages($langs->trans('TcmbKurSyncDone').' — '.$svc->output, null, 'mesgs');
	} elseif ($r === 0) {
		setEventMessages($langs->trans('TcmbKurSyncNothing').' — '.$svc->output, null, 'warnings');
	} else {
		setEventMessages($langs->trans('TcmbKurSyncFailed').': '.$svc->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'backfill') {
	$days = GETPOSTINT('days') ?: getDolGlobalInt('TCMBKUR_BACKFILL_DAYS', 30);
	$r = $svc->backfill($days, $user);
	setEventMessages($langs->trans('TcmbKurBackfillDone', $days).' — '.$svc->output, null, $r['errors'] && !$r['written'] ? 'errors' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/* ------------------------------------------------------------------ view */

llxHeader('', $langs->trans('TcmbKurSetup'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('TcmbKurSetup'), $linkback, 'title_setup');
print '<span class="opacitymedium">'.$langs->trans('TcmbKurSetupDesc').'</span><br><br>';

$main = TcmbKur::mainCurrency();
if (!isModEnabled('multicurrency')) {
	print '<div class="error">'.$langs->trans('TcmbKurErrMulticurrencyOff').'</div>';
}
if ($main !== 'TRY') {
	print '<div class="warning">'.$langs->trans('TcmbKurWarnMainCurrency', $main).'</div>';
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
foreach ($sections as $sectionKey => $sectionFields) {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="titlefieldmiddle">'.$langs->trans($sectionKey).'</td><td>'.$langs->trans('Value').'</td><td></td></tr>';
	foreach ($sectionFields as $code => $def) {
		$current = getDolGlobalString($code);
		print '<tr class="oddeven"><td>'.$langs->trans($code).'</td><td>';
		if ($def['type'] === 'select') {
			print '<select class="flat" name="'.$code.'">';
			foreach ($def['options'] as $k => $label) {
				print '<option value="'.dol_escape_htmltag($k).'"'.($current === (string) $k ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
			}
			print '</select>';
		} elseif ($def['type'] === 'yesno') {
			print '<input type="checkbox" name="'.$code.'" value="1"'.($current === '1' ? ' checked' : '').'>';
		} elseif ($def['type'] === 'int') {
			print '<input type="text" class="flat width75" name="'.$code.'" value="'.dol_escape_htmltag($current).'">';
		} else {
			print '<input type="text" class="flat" size="'.($def['size'] ?? 30).'" name="'.$code.'" value="'.dol_escape_htmltag($current).'">';
		}
		print '</td><td class="opacitymedium small">'.$langs->trans($code.'Help').'</td></tr>';
	}
	print '</table><br>';
}
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

// Actions
print load_fiche_titre($langs->trans('TcmbKurActions'), '', '');
print '<div class="tabsAction" style="margin-top:0">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=sync&token='.newToken().'">'.$langs->trans('TcmbKurSyncNow').'</a>';
print '</div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="backfill">';
print $langs->trans('TcmbKurBackfillLabel').' <input type="text" class="flat width50" name="days" value="'.getDolGlobalInt('TCMBKUR_BACKFILL_DAYS', 30).'"> '.$langs->trans('Days').' ';
print '<input type="submit" class="button small" value="'.$langs->trans('TcmbKurBackfill').'">';
print '</form>';
print '<br><span class="opacitymedium small">'.$langs->trans('TcmbKurCronHint').' <a href="'.DOL_URL_ROOT.'/cron/list.php?search_label=TcmbKur">'.$langs->trans('CronList').'</a></span><br><br>';

// Latest bulletin (live)
$b = $svc->fetchBulletin(null);
print load_fiche_titre($langs->trans('TcmbKurLatestBulletin'), '', '');
if ($b === null) {
	print '<div class="error">'.dol_escape_htmltag($svc->error).'</div>';
} else {
	$rateDate = TcmbKur::rateDateFor($b['date']);
	print '<span class="opacitymedium">'.$langs->trans('TcmbKurBulletinInfo', dol_print_date(strtotime($b['date']), 'day'), $b['no'], dol_print_date(strtotime($rateDate), 'day'), $langs->transnoentitiesnoconv('TcmbKurType'.TcmbKur::rateType())).'</span><br><br>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Currency').'</td><td>'.$langs->trans('TcmbKurUnit').'</td><td class="right">'.$langs->trans('TcmbKurTypeForexBuying').'</td><td class="right">'.$langs->trans('TcmbKurTypeForexSelling').'</td><td class="right">'.$langs->trans('TcmbKurTypeBanknoteBuying').'</td><td class="right">'.$langs->trans('TcmbKurTypeBanknoteSelling').'</td><td class="right">'.$langs->trans('TcmbKurDolibarrRate').'</td><td>'.$langs->trans('TcmbKurStored').'</td></tr>';
	$selected = TcmbKur::currencies();
	foreach ($b['rates'] as $code => $r) {
		$isSel = in_array($code, $selected, true);
		$conv = $isSel ? $svc->convert($b, $code) : null;
		$stored = $isSel ? $svc->storedRates($code, 1) : array();
		print '<tr class="oddeven'.($isSel ? '' : ' opacitymedium').'"><td><b>'.$code.'</b> <span class="opacitymedium small">'.dol_escape_htmltag($r['name_tr']).'</span></td><td>'.$r['unit'].'</td>';
		foreach (array('ForexBuying', 'ForexSelling', 'BanknoteBuying', 'BanknoteSelling') as $t) {
			print '<td class="right">'.($r[$t] !== null ? number_format($r[$t] * $r['unit'], 4, ',', '.') : '-').'</td>';
		}
		print '<td class="right">'.($conv ? number_format($conv['rate'], 8, ',', '.') : '-').'</td>';
		print '<td>'.($stored ? dol_print_date(strtotime($stored[0]['date']), 'day').' → '.number_format($stored[0]['rate'], 8, ',', '.') : ($isSel ? '<span class="opacitymedium">'.$langs->trans('None').'</span>' : '')).'</td></tr>';
	}
	print '</table></div><br>';
}

// Run log
print load_fiche_titre($langs->trans('TcmbKurRunLog'), '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Mode').'</td><td>'.$langs->trans('TcmbKurBulletin').'</td><td>'.$langs->trans('TcmbKurRateDate').'</td><td>'.$langs->trans('Status').'</td><td class="right">'.$langs->trans('TcmbKurWritten').'</td><td class="right">'.$langs->trans('TcmbKurSkipped').'</td><td>'.$langs->trans('Details').'</td></tr>';
foreach ($svc->recentLogs(20) as $l) {
	$cls = $l->status === 'ok' ? 'badge-status4' : ($l->status === 'error' ? 'badge-status8' : 'badge-status0');
	print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($l->date_run), 'dayhour').'</td><td>'.$l->mode.'</td><td>'.($l->bulletin_date ? dol_print_date(strtotime($l->bulletin_date), 'day').' '.$l->bulletin_no : '-').'</td><td>'.($l->rate_date ? dol_print_date(strtotime($l->rate_date), 'day') : '-').'</td>';
	print '<td><span class="badge '.$cls.' badge-status">'.$l->status.'</span></td><td class="right">'.$l->written.'</td><td class="right">'.$l->skipped.'</td><td class="small">'.nl2br(dol_escape_htmltag($l->detail)).'</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
