<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

/**
 * Hooks of the TCMB rates module. Only context 'aimcp' is used: registers the module's
 * MCP tools with the Dolibarr AI module (Dolibarr 24+).
 */
class ActionsTcmbKur
{
	public $db;
	public $error = '';
	public $errors = array();
	public $results = array();
	public $resprints = '';

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook 'addMcpTools' (context 'aimcp'): return McpTool instances.
	 */
	public function addMcpTools($parameters, &$object, &$action, $hookmanager)
	{
		if (!isModEnabled('tcmbkur') || !file_exists(DOL_DOCUMENT_ROOT.'/ai/class/mcptool.class.php')) {
			return 0;
		}
		dol_include_once('/tcmbkur/class/mcp/tooltcmbkur.class.php');
		$this->results = array(array(new ToolTcmbKur($parameters['db'] ?? $this->db, $parameters['user'], $parameters['conf'])));
		return 0;
	}
}
