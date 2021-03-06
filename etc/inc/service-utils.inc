<?php
/****h* pfSense/service-utils
 * NAME
 *   service-utils.inc - Service facility
 * DESCRIPTION
 *   This file contains various functions used by the pfSense service facility.
 * HISTORY
 *   $Id$
 ******
 *
 * Copyright (C) 2005-2006 Colin Smith (ethethlay@gmail.com)
 * All rights reserved.
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 * notice, this list of conditions and the following disclaimer in the
 * documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * RISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 */

/*
	pfSense_BUILDER_BINARIES:	/bin/pgrep /bin/sh /usr/bin/killall
	pfSense_MODULE:	utils
*/

$rcfileprefix = "/usr/local/etc/rc.d/";
function write_rcfile($params) {
	global $g;
	global $rcfileprefix;

	if (!file_exists("{$rcfileprefix}{$params['file']}") && !touch("{$rcfileprefix}{$params['file']}"))
		return false;

	if (!is_writable("{$rcfileprefix}{$params['file']}") || empty($params['start']))
		return false;

	$towrite = "#!/bin/sh\n";
	$towrite .= "# This file was automatically generated\n# by the {$g['product_name']} service handler.\n\n";

	/* write our rc functions */
	$towrite .= "rc_start() {\n";
	$towrite .= "\t{$params['start']}\n";
	$towrite .= "}\n\n";
	if(!empty($params['stop'])) {
		$tokill =& $params['stop'];
	} else if(!empty($params['executable'])) {
		/* just nuke the executable */
		$tokill = "/usr/bin/killall {$params['executable']}";
	} else {
		/* make an educated guess (bad) */
		$tokill = array_pop(explode('/', array_shift(explode(' ', $params['start']))));
	}
	$towrite .= "rc_stop() {\n";
	$towrite .= "\t{$tokill}\n";
	$towrite .= "}\n\n";

	/* begin rcfile logic */
	$towrite .= "case \$1 in\n\tstart)\n\t\trc_start\n\t\t;;\n\tstop)\n\t\trc_stop\n\t\t;;\n\trestart)\n\t\trc_stop\n\t\trc_start\n\t\t;;\nesac\n\n";

	file_put_contents("{$rcfileprefix}{$params['file']}", $towrite);
	@chmod("{$rcfileprefix}{$params['file']}", 0755);

	return;
}

function start_service($name) {
	global $config;
	global $rcfileprefix;

	if (empty($name))
		return;

	/* make sure service is stopped before starting */
	stop_service($name);
	sleep(2);

	if(file_exists("{$rcfileprefix}{$name}.sh")) {
		mwexec_bg("/bin/sh {$rcfileprefix}{$name}.sh start");
		return;
	}
	if($config['installedpackages']['service']) {
		foreach($config['installedpackages']['service'] as $service) {
			if(strtolower($service['name']) == strtolower($name)) {
				if($service['rcfile']) {
					$prefix = $rcfileprefix;
					if (!empty($service['prefix'])) {
						$prefix =& $service['prefix'];
					}
					if(file_exists("{$prefix}{$service['rcfile']}")) {
						mwexec_bg("{$prefix}{$service['rcfile']} start");
					}
				}
				if (!empty($service['startcmd']))
					eval($service['startcmd']);
				break;
			}
		}
	}
}

function stop_service($name) {
	global $config;
	global $rcfileprefix;

	if (empty($name))
		return;

	if ($config['installedpackages']['service']) {
		foreach($config['installedpackages']['service'] as $service) {
			if(strtolower($service['name']) == strtolower($name)) {
				if($service['rcfile']) {
					$prefix = $rcfileprefix;
					if(!empty($service['prefix'])) {
						$prefix =& $service['prefix'];
					}
					if(file_exists("{$prefix}{$service['rcfile']}")) {
						mwexec_bg("{$prefix}{$service['rcfile']} stop");
					}
					return;
				}
				if (!empty($service['stopcmd']))
					eval($service['stopcmd']);

				if(!($service['rcfile'] or $service['stopcmd'])) {
					if(is_process_running("{$service['executable']}"))
						mwexec_bg("/usr/bin/killall {$service['executable']}");
					return;
				}
				break;
			}
		}
	}
	/* finally if we get here lets simply kill the service name */
	if(is_process_running("{$name}"))
		mwexec_bg("/usr/bin/killall {$name}");
}

function restart_service($name) {
	global $config;

	if (empty($name))
		return;

	stop_service($name);
	start_service($name);

	if($config['installedpackages']['service']) {
		foreach($config['installedpackages']['service'] as $service) {
			if(strtolower($service['name']) == strtolower($name)) {
				if($service['restartcmd']) {
					eval($service['restartcmd']);
				}
				break;
			}
		}
	}
}

function is_pid_running($pidfile) {
	if (!file_exists($pidfile))
		return false;
	$running = shell_exec("/bin/pgrep -F {$pidfile} 2>/dev/null");

	return (!empty($running));
}

function is_dhcp_running($interface) {
	$status = find_dhclient_process($interface);
	if($status <> "")
		return true;
	return false;
}

function restart_service_if_running($service) {
	global $config;
	if(is_service_running($service))
		restart_service($service);
	return;
}

function is_service_running($service, $ps = "") {
	global $config;

	if(is_array($config['installedpackages']['service'])) {
		foreach($config['installedpackages']['service'] as $aservice) {
			if(strtolower($service) == strtolower($aservice['name'])) {
				if ($aservice['custom_php_service_status_command'] <> "") {
					eval("\$rc={$aservice['custom_php_service_status_command']};");
					return $rc;
				}
				if(empty($aservice['executable']))
					return false;
				if (is_process_running($aservice['executable']))
					return true;

				return false;
			}
		}
	}

	if (is_process_running($service))
		return true;

	return false;
}

?>
