<?php
/**
 * @Created by          : BlogITCode
 * @Date                : 15/06/2022
 * @File name           : index.php
 */


defined('INDEX_AUTH') OR die('Direct access not allowed!');
// IP based access limitation
require LIB.'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-bibliography');
// start the session
require SB.'admin/default/session.inc.php';
require SB.'admin/default/session_check.inc.php';
require SIMBIO.'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO.'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO.'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO.'simbio_DB/datagrid/simbio_dbgrid.inc.php';
// privileges checking
$can_read = utility::havePrivilege('bibliography', 'r');
$can_write = utility::havePrivilege('bibliography', 'w');

if (!$can_read) {
    die('<div class="errorBox">'.__('You don\'t have enough privileges to access this area!').'</div>');
}
$max_print = 50;

function httpQuery($query = [])
{
    return http_build_query(array_unique(array_merge($_GET, $query)));
}
function reverseAuthor($lastfirst) {
	if ($lastfirst == "") {
		return "";
	} else {
		list($last, $first) = explode(', ', $lastfirst);
		if ($first <>"") {
			return $first . " " . $last;
		} else {
			return $last;
		}
	}
}
/* RECORD OPERATION */
if (isset($_POST['itemID']) AND !empty($_POST['itemID']) AND isset($_POST['itemAction'])) {
    if (!$can_read) {
        die();
    }
    if (!is_array($_POST['itemID'])) {
        // make an array
        $_POST['itemID'] = array($_POST['itemID']);
    }
    /* LABEL SESSION ADDING PROCESS */
    $print_count = 0;
    if (isset($_SESSION['cards']['item'])) {
        $print_count += count($_SESSION['cards']['item']);
    }
    // loop array
    foreach ($_POST['itemID'] as $itemID) {
        if ($print_count == $max_print) {
            $limit_reach = true;
            break;
        }
        $itemID = (integer)$itemID;
		if (isset($_SESSION['cards'][$itemID])) {
			continue;
		}
		$_SESSION['cards']['item'][$itemID] = $itemID;
        $print_count++;
    }
    if (isset($limit_reach)) {
        $msg = str_replace('{max_print}', $max_print, __('Selected items NOT ADDED to print queue. Only {max_print} can be printed at once')); //mfc
        utility::jsToastr(__('Print Catalog Format'), $msg, 'warning');
    } else {
        // update print queue count object
        echo '<script type="text/javascript">parent.$(\'#queueCount\').html(\''.$print_count.'\');</script>';
        utility::jsToastr(__('Print Catalog Format'), __('Selected items added to print queue'), 'success');
    }
    exit();
}

// clean print queue
if (isset($_GET['action']) AND $_GET['action'] == 'clear') {
    utility::jsToastr(__('Print Catalog Format'), __('Print queue cleared!'), 'success');
    echo '<script type="text/javascript">parent.$(\'#queueCount\').html(\'0\');</script>';
    unset($_SESSION['cards']);
    exit();
}

// on print action
if (isset($_GET['action']) AND $_GET['action'] == 'print') {
    // check if label session array is available
    if (!isset($_SESSION['cards']['item']) && !isset($_SESSION['cards']['biblio'])) {
        utility::jsToastr(__('Print Catalog Format'), __('There is no data to print!'), 'error');
        die();
    }

    // include printed settings configuration file
    include SB.'admin'.DS.'admin_template'.DS.'printed_settings.inc.php';
    // check for custom template settings
    $custom_settings = SB.'admin'.DS.$sysconf['admin_template']['dir'].DS.$sysconf['template']['theme'].DS.'printed_settings.inc.php';
    if (file_exists($custom_settings)) {
      include $custom_settings;
    }

    // load catalog settings from database to override 
    loadPrintSettings($dbs, 'catalog');

    // concat item ID
    $item_ids = '';
    if (isset($_SESSION['cards']['item'])) {
        foreach ($_SESSION['cards']['item'] as $id) {
            $item_ids .= $id.',';
        }
    }
    // strip the last comma
    $item_ids = substr_replace($item_ids, '', -1);

    $criteria = "b.biblio_id IN($item_ids)";

    $biblio_q = $dbs->query('SELECT b.biblio_id, b.title as title, b.call_number, b.sor, b.collation, b.notes,
		CONCAT(\'[\', g.gmd_name, \'].\') as gmd,
		CONCAT(b.edition, \'.\') as edition, b.isbn_issn,
		CONCAT(pp.place_name, \' : \', p.publisher_name, \', \', b.publish_year, \'.\') as publisher,
		CONCAT(b.collation, \'.\') as physic,
		CONCAT(b.series_title, \'.\') as series
	FROM biblio as b
	LEFT JOIN mst_gmd as g on b.gmd_id = g.gmd_id
	LEFT JOIN mst_publisher as p on b.publisher_id = p.publisher_id
	LEFT JOIN mst_place as pp on b.publish_place_id = pp.place_id
	WHERE '.$criteria);

	$katalog = "";
	$item = 0;
    while ($biblio_d = $biblio_q->fetch_array()) {
        if($sysconf['print']['catalog']['self_list_card'] == '1'){
		  $tajuk[] = "&nbsp;";
        }
        if($sysconf['print']['catalog']['title_card'] == '1'){
		  $tajuk[] = $biblio_d['title'];
        }
		// author
		$author_q = $dbs->query('SELECT a.author_name
		   FROM biblio_author as ba
		   LEFT JOIN mst_author as a on ba.author_id = a.author_id
		   WHERE ba.biblio_id = '. $biblio_d['biblio_id']);
		$biblio_d['author'] = "";
		$i = 0;
		while ($author_d = $author_q->fetch_row()) {
			$biblio_d['author'] .= reverseAuthor($author_d[0]) . ', ';
			$i += 1;
			if ($i == 1) { $mainauthor = $author_d[0]; }
			if ($i > 1 && $sysconf['print']['catalog']['author_card'] == '1') { $tajuk[] = $author_d[0]; }
			if ($i >= 3) { break; }
		}
		// strip the last comma
		if ($biblio_d['sor'] <> "") {
			$biblio_d['author'] = $biblio_d['sor'];
		} else {
			$biblio_d['author'] = substr_replace($biblio_d['author'], '', -2);
		}

        // subject
		$subject_q = $dbs->query('SELECT t.topic
		   FROM biblio_topic as bt
		   LEFT JOIN mst_topic as t on bt.topic_id = t.topic_id
		   WHERE bt.biblio_id = '. $biblio_d['biblio_id']);
		$biblio_d['subject'] = "";
		$i = 0;
		while ($subject_d = $subject_q->fetch_row()) {
			$biblio_d['subject'] .= $subject_d[0]. '; ';
            if($sysconf['print']['catalog']['subject_card'] == '1'){
			  $tajuk[] = $subject_d[0];
            }
			$i += 1;
			if ($i >= 3) { break; }
		}
		$biblio_d['subject'] = substr_replace($biblio_d['subject'], '', -2);

		// explode label data by space
		$sliced_label = explode(' ', $biblio_d['call_number'], 5);
		if (count($sliced_label) < 3) {
			for ($i=count($sliced_label); $i<3; ++$i) {
				$sliced_label[$i]= "&nbsp";
			}
		}
		// number of copy
		$number_q = $dbs->query('SELECT count(item_id)
		   FROM item
		   WHERE biblio_id = '. $biblio_d['biblio_id']. ' GROUP BY biblio_id');
		$biblio_d['copies'] = "&nbsp;";
		
		while ($number_d = $number_q->fetch_row()) {
			$biblio_d['copies'] = $number_d[0] ." " . __('Copies');
		}
		// lay ra ma dang ky ca biet
		$get_dkcb = $dbs->query('SELECT item_code FROM item WHERE biblio_id = '. $biblio_d['biblio_id']);	   
		$biblio_d['dkcb'] = "";
		$i = 0;
		while($row_dkcb = $get_dkcb->fetch_row()) {
			$biblio_d['dkcb'] .= $row_dkcb[0]. ', ';
		$i += 1;
		 }
	 
		/* check for break page */
		
		$katalog .= "<div class=kotak>";
		$katalog .= "
			<span style='font-weight:600; text-transform: uppercase;'>".$biblio_d['author'].".</span> 
			<span style='font-weight:600'>".$biblio_d['title']."/</span>
			".$biblio_d['author'].".-
			".$biblio_d['publisher']."-
			".$biblio_d['collation']."<br>
			<span style='font-weight:600; padding-left:15px;font-style: italic;'>Tóm tắt:</span> ".$biblio_d['notes']."<br>
			<span style='font-weight:600; padding-left:15px;font-style: italic;'>Chủ đề:</span> ".$biblio_d['subject']."<br>
			<span style='font-weight:600; padding-left:15px;font-style: italic;'>Phân loại:</span> ".$biblio_d['call_number']."<br>
			<span style='font-weight:600; padding-left:15px;font-style: italic;'>Đăng ký cá biệt:</span> ".$biblio_d['dkcb']."
			</div><br>\n";
		
		unset($tajuk);
		unset($sliced_label);
		
    }

	

    // create html ouput of images
    $html_str = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'."\n";
    $html_str .= '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>Document Label Print Result</title>'."\n";
    $html_str .= '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    $html_str .= '<meta http-equiv="Pragma" content="no-cache" /><meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, post-check=0, pre-check=0" /><meta http-equiv="Expires" content="Sat, 26 Jul 1997 05:00:00 GMT" />';
    $html_str .= '<style type="text/css">'."\n";
    $html_str .= '@media print {'."\n";
    $html_str .= '.doNotPrint { display: none; }'."\n";
    $html_str .= '}'."\n";
    $html_str .= '.data {FONT-FAMILY: verdana; FONT-SIZE: 10px; HEIGHT: 20px; PADDING-LEFT: 5px; PADDING-TOP: 0px; text-valign: bottom;  background:#ffffff}'."\n";
    $html_str .= '.callno {FONT-FAMILY: verdana; FONT-SIZE: 10px; HEIGHT: 20px; PADDING-LEFT: 5px; PADDING-TOP: 0px; vertical-align: top;  background:#ffffff}'."\n";
    $html_str .= '.kata {FONT-FAMILY: verdana; FONT-SIZE: 11px;}'."\n";
    $html_str .= '.kotak {FONT-FAMILY: verdana; FONT-SIZE: 11px; HEIGHT: 20px; FONT-STYLE: bold; PADDING-LEFT: 5px; PADDING-RIGHT: 5px; text-valign: bottom;background:#ffffff;height: auto;}'."\n";
    $html_str .= '</style>'."\n";
    $html_str .= '</head>'."\n";
    $html_str .= '<body>'."\n";
    $html_str .= '<a href="#" class="doNotPrint" onclick="window.print()">' . __('Print Again') . '</a>'."\n";
    $html_str .= '<table border=0 cellpadding=0 cellspacing=5>'."\n";
	  $html_str .= $katalog;
    $html_str .= '</table>'."\n";
    $html_str .= '<script type="text/javascript">self.print();</script>'."\n";
    $html_str .= '</body></html>'."\n";
    // unset the session
    unset($_SESSION['cards']);

    // write to file
    $print_file_name = 'catalog_print_result_'.strtolower(str_replace(' ', '_', $_SESSION['uname'])).'.html';
    $file_write = @file_put_contents(UPLOAD.$print_file_name, $html_str);
    if ($file_write) {
        echo '<script type="text/javascript">parent.$(\'#queueCount\').html(\'0\');</script>';
        // open result in new window
        echo '<script type="text/javascript">top.$.colorbox({href: "'.SWB.FLS.'/'.$print_file_name.'", iframe: true, width: 800, height: 500, title: "'.__('Catalog Printing').'"})</script>';
    } else { utility::jsToastr(__('Print Catalog Format'), str_replace('{directory}', SB.FLS, __('ERROR! Catalog card failed to generate, possibly because {directory} directory is not writable')), 'error'); }
    exit();
}

/* search form */
?>

<div class="menuBox">
<div class="menuBoxInner printIcon">
	<div class="per_title">
    <h2><?php echo __('Print Catalogue Format'); ?></h2>
    </div>
	<div class="sub_section">
    <div class="btn-group">
        <a target="blindSubmit" href="<?php echo $_SERVER['PHP_SELF'] . '?' . httpQuery(['action' => 'clear']) ;?>" class="notAJAX btn btn-default"><?php echo __('Clear Print Queue'); ?></a>
        <a target="blindSubmit" href="<?php echo $_SERVER['PHP_SELF'] . '?' . httpQuery(['action' => 'print']) ;?>" class="notAJAX btn btn-default"><?php echo __('Print Catalog for Selected Data'); ?></a>
	</div>
    <form name="search" action="<?php echo $_SERVER['PHP_SELF'] . '?' . httpQuery(); ?>" id="search" method="get" class="form-inline">
    <?php echo __('Search'); ?>
    <input type="text" name="keywords" class="form-control col-md-3" />
    <input type="submit" id="doSearch" value="<?php echo __('Search'); ?>" class="btn btn-default" />
    </form>
    </div>
    <div class="infoBox">
        <?php
        echo __('Maximum').' <font style="color: #FF0000">'.$max_print.'</font> '.__('records can be printed at once. Currently there is').' '; //mfc
        if (isset($_SESSION['cards'])) {
          echo '<font id="queueCount" style="color: #FF0000">'.count($_SESSION['cards']).'</font>';
        } else { echo '<font id="queueCount" style="color: #FF0000">0</font>'; }
          echo ' '.__('in queue waiting to be printed.'); //mfc
        ?>
    </div>
</div>
</div>
<?php
/* search form end */

// create datagrid
$datagrid = new simbio_datagrid();
/* BIBLIOGRAPHY LIST */
require SIMBIO.'simbio_UTILS/simbio_tokenizecql.inc.php';
require LIB.'biblio_list_model.inc.php';
// index choice
if ($sysconf['index']['type'] == 'index' || ($sysconf['index']['type'] == 'sphinx' && file_exists(LIB.'sphinx/sphinxapi.php'))) {
    if ($sysconf['index']['type'] == 'sphinx') {
        require LIB.'sphinx/sphinxapi.php';
        require LIB.'biblio_list_sphinx.inc.php';
    } else {
        require LIB.'biblio_list_index.inc.php';
    }
    // table spec
    $table_spec = 'search_biblio AS `index` LEFT JOIN `biblio` ON `index`.biblio_id=`biblio`.biblio_id';
    if ($can_read) {
        $datagrid->setSQLColumn('index.biblio_id, index.title as '.__('Title').', index.author as '.__('Author'));
    }

} else {
    require LIB.'biblio_list.inc.php';
    // table spec
    $table_spec = 'biblio LEFT JOIN item as i ON biblio.biblio_id=i.biblio_id';
    if ($can_read) {
        $datagrid->setSQLColumn('biblio.biblio_id, biblio.title, COUNT(i.item_id) as '.__('Item'));
    }

}
$datagrid->setSQLorder('biblio.last_update DESC');
// is there any search
if (isset($_GET['keywords']) AND $_GET['keywords']) {
    $keywords = utility::filterData('keywords', 'get', true, true, true);
    $searchable_fields = array('title', 'class', 'callnumber');
    $search_str = '';
    // if no qualifier in fields
    if (!preg_match('@[a-z]+\s*=\s*@i', $keywords)) {
        foreach ($searchable_fields as $search_field) {
            $search_str .= $search_field.'='.$keywords.' OR ';
        }
    } else {
        $search_str = $keywords;
    }
    $biblio_list = new biblio_list($dbs, 20);
    $criteria = $biblio_list->setSQLcriteria($search_str);
}
if (isset($criteria)) {
  $datagrid->setSQLcriteria('('.$criteria['sql_criteria'].')');
}
$datagrid->sql_group_by = "biblio.biblio_id";

// set table and table header attributes
$datagrid->table_attr = 'id="dataList" class="s-table table"';
$datagrid->table_header_attr = 'class="dataListHeader" style="font-weight: bold;"';
// edit and checkbox property
$datagrid->edit_property = false;
$datagrid->chbox_property = array('itemID', __('Add'));
$datagrid->chbox_action_button = __('Add To Print Queue');
$datagrid->chbox_confirm_msg = __('Add to print queue?');
// set delete proccess URL
$datagrid->chbox_form_URL = $_SERVER['PHP_SELF'] . '?' . httpQuery();
$datagrid->column_width = array(0 => '75%', 1 => '20%');
// put the result into variables
$datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 20, $can_read);
if (isset($_GET['keywords']) AND $_GET['keywords']) {
    $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords')); //mfc
    echo '<div class="infoBox">'.$msg.' : "'.htmlspecialchars($_GET['keywords']).'"<div>'.__('Query took').' <b>'.$datagrid->query_time.'</b> '.__('second(s) to complete').'</div></div>'; //mfc
}
echo $datagrid_result;
/* main content end */

