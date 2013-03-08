<?php 
header ("Content-Type:text/xml");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT"); 
header("Cache-Control: no-store, no-cache, must-revalidate"); 
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
echo '<'.'?xml version="1.0" encoding="UTF-8"?'.'>';

require_once(dirname(__FILE__).'/global.php');
require_once(dirname(__FILE__).'/users/users.php');
require_once(dirname(__FILE__).'/paginator.class.php');

$searchstring = null;
if (array_key_exists('search', $_GET) && trim($_GET['search']) != '') {
	$searchstring = "urls.url LIKE '%".mysql_real_escape_string(trim($_GET['search']))."%'";

	$current_user = User::get();
	if (!is_null($current_user)) {
		$current_user->recordActivity(SHOWSLOW_ACTIVITY_URL_SEARCH);
	}
}


$SECTION = 'xml';

$subset = null;

if (is_array($URLGroups) && count($URLGroups) > 0) {
	$params = array();

	if (!is_null($searchstring)) {
		$params['search'] = urlencode(trim($_GET['search']));
	}

	$paramsstring = '';

	if ($current_group == '__show_all__') {

	} else {
		$id = '__show_all__';

		if ($DefaultURLGroup == $id) {
			$linkparams = $params;
		} else {
			$linkparams = array_merge($params, array('group' => urlencode($id)));
		}

		$paramsstring = '';
		if (count($linkparams) > 0) {
			foreach ($linkparams as $name => $param) {
				$paramsstring .= $paramsstring == '' ? '?' : '&';
				$paramsstring .= $name.'='.$param;
			}
		}
	}

	foreach ($URLGroups as $id => $group) {
		if ($current_group == $id) {
			$subset = $group['urls'];
		} else {
			if ($DefaultURLGroup == $id) {
				$linkparams = $params;
			} else {
				$linkparams = array_merge($params, array('group' => urlencode($id)));
			}

			$paramsstring = '';
			if (count($linkparams) > 0) {
				foreach ($linkparams as $name => $param) {
					$paramsstring .= $paramsstring == '' ? '?' : '&';
					$paramsstring .= $name.'='.$param;
				}
			}
		}
	}
}

$perPage = 100;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
	$page = 1;
}
$offset = ($page - 1) * $perPage;

$subsetstring = null;
$first = true;
if (is_array($subset)) {
	foreach ($subset as $url) {
		if ($first) {
			$first = false;
		} else {
			$subsetstring .= ' OR ';
		}
		$subsetstring .= "urls.url LIKE '".mysql_real_escape_string($url)."%'";
	}
}

$query = "SELECT count(*)
	FROM urls
		LEFT JOIN yslow2 ON urls.yslow2_last_id = yslow2.id
		LEFT JOIN pagespeed ON urls.pagespeed_last_id = pagespeed.id
		LEFT JOIN dynatrace ON urls.dynatrace_last_id = dynatrace.id
		LEFT JOIN har ON urls.har_last_id = har.id
	WHERE last_update IS NOT NULL";

if (!is_null($subsetstring)) {
	$query .= " AND ($subsetstring)";
}

if (!is_null($searchstring)) {
	$query .= " AND $searchstring";
}

$result = mysql_query($query);
$row = mysql_fetch_row($result);
$total = $row[0];

$pages = new Paginator();
$pages->items_total = $total;
$pages->mid_range = 7;
$pages->xml = true;
$pages->items_per_page = $perPage;

$query = 'SELECT url, last_update,
		yslow2.o as o,
		pagespeed.o as ps_o,
		dynatrace.rank as dt_o
	FROM urls
		LEFT JOIN yslow2 ON urls.yslow2_last_id = yslow2.id
		LEFT JOIN pagespeed ON urls.pagespeed_last_id = pagespeed.id
		LEFT JOIN dynatrace ON urls.dynatrace_last_id = dynatrace.id
		LEFT JOIN har ON urls.har_last_id = har.id
	WHERE last_update IS NOT NULL';

if (!is_null($subsetstring)) {
	$query .= " AND ($subsetstring)";
}
if (!is_null($searchstring)) {
	$query .= " AND $searchstring";
}

$query .= sprintf(" ORDER BY url LIMIT %d OFFSET %d", $perPage, $offset);

$result = mysql_query($query);

if (!$result) {
	error_log(mysql_error());
}

$yslow = false;
$pagespeed = false;
$dynatrace = false;
$pagetest = false;

$rows = array();
while ($row = mysql_fetch_assoc($result)) {
	$rows[] = $row;

	if ($enabledMetrics['yslow'] && !$yslow && !is_null($row['o'])) {
		$yslow = true;
	}
	if ($enabledMetrics['pagespeed'] && !$pagespeed && !is_null($row['ps_o'])) {
		$pagespeed = true;
	}
	if ($enabledMetrics['dynatrace'] && !$dynatrace && !is_null($row['dt_o'])) {
		$dynatrace = true;
	}
}

?>

<root>
<title><?php echo 'Query: '.$_GET['search'].' Page: '.$_GET['page'] ?> - Show Slow</title>
<?php

if ($yslow || $pagespeed || $dynatrace || $pagetest) {
	$pages->paginate($showslow_base.'xml.php');
?>

<data>

<?php 

foreach ($rows as $row) {
	?><result id="<?php echo htmlentities($row['url'])?>">
		<updated><?php echo htmlentities($row['last_update'])?></updated>
		<url>/details/?url=<?php echo urlencode($row['url'])?></url>
	<?php if (!$yslow) {?>
	<?php }else if (is_null($row['o'])) {?>
		<yslow title="No data collected">no data</yslow>
	<?php }else{?>
		<yslow title="Current YSlow grade: <?php echo prettyScore($row['o'])?> (<?php echo $row['o']?>)" prettyScore="<?php echo prettyScore($row['o'])?>"><?php echo $row['o']?></yslow>
	<?php }?>
	<?php if (!$pagespeed) {?>
	<?php }else if (is_null($row['ps_o'])) {?>
		<pagespeed title="No data collected">no data</pagespeed>
	<?php }else{?>
		<pagespeed title="Current Page Speed score: <?php echo prettyScore($row['ps_o'])?> (<?php echo $row['ps_o']?>)" prettyScore="<?php echo prettyScore($row['ps_o'])?>"><?php echo $row['ps_o']?></pagespeed>
	<?php }?>
	<?php if (!$dynatrace) {?>
	<?php }else if (is_null($row['dt_o'])) {?>
		<dynatrace title="No data collected">no data</dynatrace>
	<?php }else{?>
		<pagespeed title="Current dynaTrace score: <?php echo prettyScore($row['dt_o'])?> (<?php echo $row['dt_o']?>)" prettyScore="<?php echo prettyScore($row['dt_o'])?>"><?php echo $row['dt_o']?></dynatrace>
	<?php }?>

	</result>
<?php
}

mysql_free_result($result);
?>
</data>
<?php
		if ($pages->num_pages > 1) {
	?><pagination>
<total><? echo $pages->num_pages ?></total>
<?php
		echo $pages->display_pages();
	?></pagination>
<?php
	}

} else {
	?><error>No data is gathered yet</error><?php
	}
?>

</root>