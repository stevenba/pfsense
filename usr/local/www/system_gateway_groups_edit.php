<?php 
/* $Id$ */
/*
	system_gateway_groups_edit.php
	part of pfSense (http://pfsense.com)
	
	Copyright (C) 2010 Seth Mos <seth.mos@dds.nl>.
	All rights reserved.
	
	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:
	
	1. Redistributions of source code must retain the above copyright notice,
	   this list of conditions and the following disclaimer.
	
	2. Redistributions in binary form must reproduce the above copyright
	   notice, this list of conditions and the following disclaimer in the
	   documentation and/or other materials provided with the distribution.
	
	THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
	AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
	AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
	OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
	SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
	INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
	CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
	ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
	POSSIBILITY OF SUCH DAMAGE.
*/
/*
	pfSense_MODULE:	routing
*/

##|+PRIV
##|*IDENT=page-system-gateways-editgatewaygroups
##|*NAME=System: Gateways: Edit Gateway Groups page
##|*DESCR=Allow access to the 'System: Gateways: Edit Gateway Groups' page.
##|*MATCH=system_gateway_groups_edit.php*
##|-PRIV

require("guiconfig.inc");

if (!is_array($config['gateways']['gateway_group']))
	$config['gateways']['gateway_group'] = array();

$a_gateway_groups = &$config['gateways']['gateway_group'];
$a_gateways = return_gateways_array();

$categories = array('down' => gettext("Member Down"),
                'downloss' => gettext("Packet Loss"),
                'downlatency' => gettext("High Latency"),
                'downlosslatency' => gettext("Packet Loss or High Latency"));

$id = $_GET['id'];
if (isset($_POST['id']))
	$id = $_POST['id'];

if (isset($_GET['dup'])) {
	$id = $_GET['dup'];
}

if (isset($id) && $a_gateway_groups[$id]) {
	$pconfig['name'] = $a_gateway_groups[$id]['name'];
	$pconfig['item'] = &$a_gateway_groups[$id]['item'];
	$pconfig['descr'] = $a_gateway_groups[$id]['descr'];
	$pconfig['trigger'] = $a_gateway_groups[$id]['trigger'];
}

if (isset($_GET['dup']))
	unset($id);

if ($_POST) {

	unset($input_errors);
	$pconfig = $_POST;

	/* input validation */
	$reqdfields = explode(" ", "name");
	$reqdfieldsn = explode(",", "Name");
	
	do_input_validation($_POST, $reqdfields, $reqdfieldsn, &$input_errors);
	
	if (! isset($_POST['name'])) {
		$input_errors[] = gettext("A valid gateway group name must be specified.");
	}
	if (! is_validaliasname($_POST['name'])) {
		$input_errors[] = gettext("The gateway name must not contain invalid characters.");
	}

	if (isset($_POST['name'])) {
		/* check for overlaps */
		if(is_array($a_gateway_groups)) {
			foreach ($a_gateway_groups as $gateway_group) {
				if (isset($id) && ($a_gateway_groups[$id]) && ($a_gateway_groups[$id] === $gateway_group))
					continue;

				if ($gateway_group['name'] == $_POST['name']) {
					$input_errors[] = sprintf(gettext('A gateway group with this name "%s" already exists.'), $_POST['name']);
					break;
				}
			}
		}
	}

	/* Build list of items in group with priority */
	$pconfig['item'] = array();
	foreach($a_gateways as $gwname => $gateway) {
		if($_POST[$gwname] > 0) {
			/* we have a priority above 0 (disabled), add item to list */
			$pconfig['item'][] = "{$gwname}|{$_POST[$gwname]}";
		}
		/* check for overlaps */
		if ($_POST['name'] == $gwname)
			$input_errors[] = sprintf(gettext('A gateway group cannot have the same name with a gateway "%s" please choose another name.'), $_POST['name']);

	}
	if(count($pconfig['item']) == 0)
		$input_errors[] = gettext("No gateway(s) have been selected to be used in this group");

	if (!$input_errors) {
		$gateway_group = array();
		$gateway_group['name'] = $_POST['name'];
		$gateway_group['item'] = $pconfig['item'];
		$gateway_group['trigger'] = $_POST['trigger'];
		$gateway_group['descr'] = $_POST['descr'];

		if (isset($id) && $a_gateway_groups[$id])
			$a_gateway_groups[$id] = $gateway_group;
		else
			$a_gateway_groups[] = $gateway_group;
		
		mark_subsystem_dirty('staticroutes');
		
		write_config();
		
		header("Location: system_gateway_groups.php");
		exit;
	}
}

$pgtitle = array(gettext("System"),gettext("Gateways"),gettext("Edit gateway"));
$statusurl = "status_gateway_groups.php";

include("head.inc");

?>

<body link="#0000CC" vlink="#0000CC" alink="#0000CC">
<?php include("fbegin.inc"); ?>
<?php if ($input_errors) print_input_errors($input_errors); ?>
            <form action="system_gateway_groups_edit.php" method="post" name="iform" id="iform">
              <table width="100%" border="0" cellpadding="6" cellspacing="0">
		<tr>
			<td colspan="2" valign="top" class="listtopic"><?=gettext("Edit gateway entry"); ?></td>
		</tr>	
                <tr>
                  <td width="22%" valign="top" class="vncellreq"><?=gettext("Group Name"); ?></td>
                  <td width="78%" class="vtable"> 
                    <input name="name" type="text" class="formfld unknown" id="name" size="20" value="<?=htmlspecialchars($pconfig['name']);?>"> 
                    <br> <span class="vexpl"><?=gettext("Group Name"); ?></span></td>
                </tr>
		<tr>
                  <td width="22%" valign="top" class="vncellreq"><?=gettext("Gateway Priority"); ?></td>
                  <td width="78%" class="vtable"> 
		<?php
			foreach($a_gateways as $gwname => $gateway) {
				if(!empty($pconfig['item'])) {
					$af = explode("|", $pconfig['item'][0]);
					if(!validate_address_family(lookup_gateway_ip_by_name($af[0]), $gateway['gateway']))
						continue;
				}
				$selected = array();
				$interface = $gateway['interface'];
				foreach((array)$pconfig['item'] as $item) {
					$itemsplit = explode("|", $item);
					if($itemsplit[0] == $gwname) {
						$selected[$itemsplit[1]] = "selected";
						break;
					} else {
						$selected[0] = "selected";
					}
				}
				echo "<select name='{$gwname}' class='formfldselect' id='{$gwname}'>";
				echo "<option value='0' $selected[0] >" . gettext("Never") . "</option>";
				echo "<option value='1' $selected[1] >" . gettext("Tier 1") . "</option>";
				echo "<option value='2' $selected[2] >" . gettext("Tier 2") . "</option>";
				echo "<option value='3' $selected[3] >" . gettext("Tier 3") . "</option>";
				echo "<option value='4' $selected[4] >" . gettext("Tier 4") . "</option>";
				echo "<option value='5' $selected[5] >" . gettext("Tier 5") . "</option>";
				echo "</select> <strong>{$gateway['name']} - {$gateway['descr']}</strong><br />";
		 	}
		?>
			<br/><span class="vexpl">
			<strong><?=gettext("Link Priority"); ?></strong> <br />
			<?=gettext("The priority selected here defines in what order failover and balancing of links will be done. " .
			"Multiple links of the same priority will balance connections until all links in the priority will be exhausted. " .
			"If all links in a priority level are exhausted we will use the next available link(s) in the next priority level.") ?>
			</span><br />
		   </td>
                </tr>
		  </td>
		</tr>
                <tr>
                  <td width="22%" valign="top" class="vncellreq"><?=gettext("Trigger Level"); ?></td>
                  <td width="78%" class="vtable">
			<select name='trigger' class='formfldselect' id='trigger'>
			<?php
				foreach ($categories as $category => $categoryd) {
				        echo "<option value=\"$category\"";
				        if ($category == $pconfig['trigger']) echo " selected";
					echo ">" . htmlspecialchars($categoryd) . "</option>\n";
				}
			?>
			</select>
                    <br> <span class="vexpl"><?=gettext("When to trigger exclusion of a member"); ?></span></td>
                </tr>
		<tr>
                  <td width="22%" valign="top" class="vncell"><?=gettext("Description"); ?></td>
                  <td width="78%" class="vtable"> 
                    <input name="descr" type="text" class="formfld unknown" id="descr" size="40" 
value="<?=htmlspecialchars($pconfig['descr']);?>">
                    <br> <span class="vexpl"><?=gettext("You may enter a description here for your reference (not parsed)."); ?></span></td>
                </tr>
                <tr>
                  <td width="22%" valign="top">&nbsp;</td>
                  <td width="78%"> 
                    <input name="Submit" type="submit" class="formbtn" value="<?=gettext("Save");?>"> <input type="button" value="<?=gettext("Cancel"); ?>" class="formbtn"  onclick="history.back()">
                    <?php if (isset($id) && $a_gateway_groups[$id]): ?>
                    <input name="id" type="hidden" value="<?=htmlspecialchars($id);?>">
                    <?php endif; ?>
                  </td>
                </tr>
              </table>
</form>
<?php include("fend.inc"); ?>
<script language="JavaScript">
	enable_change();
</script>
</body>
</html>
