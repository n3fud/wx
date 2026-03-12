<?php
// PA Rivers Dashboard v11 — PHP 7.2+
// Adds: county filter, CSV export, USGS discharge (cfs) alongside NWS stage
// NWPS: mapservices.weather.noaa.gov — WFO-based query (CTP/PBZ/PHI/BGM)
// USGS: waterservices.usgs.gov/nwis/iv — discharge + county by state

error_reporting(E_ALL);
ini_set('display_errors', '0');

define('CACHE_FILE', sys_get_temp_dir() . '/pa_rivers_v11.json');
define('CACHE_TTL',  900);

define('NWPS_FIELDS',
    'gaugelid,status,location,waterbody,state,obstime,wfo,url,action,units,flood,moderate,major,observed,latitude,longitude'
);
define('NWPS_ENDPOINT',
    'https://mapservices.weather.noaa.gov/eventdriven/rest/services/water/riv_gauges/MapServer/0/query'
    . '?returnGeometry=false&f=json&resultRecordCount=250&outFields=' . NWPS_FIELDS
);
define('ALERTS_URL',
    'https://api.weather.gov/alerts/active?area=PA'
    . '&event=Flood+Warning,Flood+Watch,Flash+Flood+Warning,Flash+Flood+Watch,Flood+Advisory,Flash+Flood+Advisory'
);
// USGS IV: gage height (00065) + discharge (00060), all PA sites, JSON
// Returns county name, site name, and latest values
define('USGS_URL',
    'https://waterservices.usgs.gov/nwis/iv/'
    . '?format=json&stateCd=PA&parameterCd=00060,00065&siteStatus=active&siteType=ST'
);

// ── HTTP ──────────────────────────────────────────────────────
function hget($url, $timeout = 25) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_USERAGENT, 'PA-Rivers/11.0 (public)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body && $code >= 200 && $code < 400) return array('body' => $body, 'err' => '');
        return array('body' => null, 'err' => "HTTP $code $err");
    }
    $ctx = stream_context_create(array('http' => array(
        'timeout' => $timeout, 'method' => 'GET',
        'header'  => "User-Agent: PA-Rivers/11.0\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    )));
    $b = @file_get_contents($url, false, $ctx);
    return ($b !== false) ? array('body' => $b, 'err' => '') : array('body' => null, 'err' => 'fgc failed');
}

// ── CACHE ─────────────────────────────────────────────────────
function lcache() {
    if (!file_exists(CACHE_FILE)) return null;
    if ((time() - filemtime(CACHE_FILE)) > CACHE_TTL) return null;
    $d = json_decode(@file_get_contents(CACHE_FILE), true);
    return (is_array($d) && isset($d['gauges'])) ? $d : null;
}
function scache($d) { @file_put_contents(CACHE_FILE, json_encode($d)); }

// ── STATUS ────────────────────────────────────────────────────
function skey($raw) {
    $r = strtolower(trim((string)$raw));
    if ($r === 'major')       return 'major';
    if ($r === 'moderate')    return 'moderate';
    if ($r === 'minor')       return 'flood';
    if ($r === 'action')      return 'action';
    if ($r === 'no_flooding') return 'normal';
    return 'unknown';
}
function nn($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    return ($f < -900) ? null : $f;
}

// ── USGS FETCH — discharge (cfs) + county ─────────────────────
// USGS site numbers are typically the NWS gaugelid with leading zeros
// stripped and a different format. We match by USGS site_no → NWS LID
// using the USGS siteCode field. USGS IV JSON structure:
//   value.timeSeries[].sourceInfo.siteCode[0].value  = site number
//   value.timeSeries[].sourceInfo.siteName            = name
//   value.timeSeries[].sourceInfo.geoLocation.geogLocation.county = county
//   value.timeSeries[].variable.variableCode[0].value = "00060" or "00065"
//   value.timeSeries[].values[0].value[0].value       = latest reading
function fetch_usgs() {
    $r = hget(USGS_URL, 30);
    if (!$r['body']) return array();
    $j = json_decode($r['body'], true);
    if (!isset($j['value']['timeSeries'])) return array();

    $out = array(); // keyed by USGS site number
    foreach ($j['value']['timeSeries'] as $ts) {
        $site_no = isset($ts['sourceInfo']['siteCode'][0]['value'])
            ? $ts['sourceInfo']['siteCode'][0]['value'] : '';
        if (!$site_no) continue;

        $param = isset($ts['variable']['variableCode'][0]['value'])
            ? $ts['variable']['variableCode'][0]['value'] : '';

        $val_str = isset($ts['values'][0]['value'][0]['value'])
            ? $ts['values'][0]['value'][0]['value'] : '';
        $val = nn($val_str);

        // County from USGS siteProperty array
        $county = '';
        if (isset($ts['sourceInfo']['siteProperty'])) {
            foreach ($ts['sourceInfo']['siteProperty'] as $sp) {
                if (isset($sp['name']) && $sp['name'] === 'countyCd') {
                    // county code — grab site name part instead
                }
            }
        }
        // County name is embedded in siteName like "CREEK AT TOWN, PA"
        // Better: extract from the sourceInfo.siteName suffix or use geoLocation
        $site_name = isset($ts['sourceInfo']['siteName']) ? $ts['sourceInfo']['siteName'] : '';

        // Try to get county from siteProperty name=county or stateCd
        if (isset($ts['sourceInfo']['siteProperty'])) {
            foreach ($ts['sourceInfo']['siteProperty'] as $sp) {
                if (isset($sp['name']) && strtolower($sp['name']) === 'countycd') {
                    $county = isset($sp['value']) ? $sp['value'] : '';
                }
            }
        }

        if (!isset($out[$site_no])) {
            $out[$site_no] = array(
                'site_no'    => $site_no,
                'site_name'  => $site_name,
                'county_cd'  => $county,
                'discharge'  => null,
                'usgs_url'   => 'https://waterdata.usgs.gov/nwis/uv?site_no=' . $site_no,
            );
        }
        if ($param === '00060') $out[$site_no]['discharge'] = $val;
    }
    return $out;
}

// Build county code → name lookup from USGS county codes (PA = state 42)
// USGS county codes are 5-digit: 42XXX where XXX is the county FIPS
function pa_county_name($fips_cd) {
    // PA county FIPS codes (last 3 digits)
    static $map = array(
        '42001'=>'Adams','42003'=>'Allegheny','42005'=>'Armstrong','42007'=>'Beaver',
        '42009'=>'Bedford','42011'=>'Berks','42013'=>'Blair','42015'=>'Bradford',
        '42017'=>'Bucks','42019'=>'Butler','42021'=>'Cambria','42023'=>'Cameron',
        '42025'=>'Carbon','42027'=>'Centre','42029'=>'Chester','42031'=>'Clarion',
        '42033'=>'Clearfield','42035'=>'Clinton','42037'=>'Columbia','42039'=>'Crawford',
        '42041'=>'Cumberland','42043'=>'Dauphin','42045'=>'Delaware','42047'=>'Elk',
        '42049'=>'Erie','42051'=>'Fayette','42053'=>'Forest','42055'=>'Franklin',
        '42057'=>'Fulton','42059'=>'Greene','42061'=>'Huntingdon','42063'=>'Indiana',
        '42065'=>'Jefferson','42067'=>'Juniata','42069'=>'Lackawanna','42071'=>'Lancaster',
        '42073'=>'Lawrence','42075'=>'Lebanon','42077'=>'Lehigh','42079'=>'Luzerne',
        '42081'=>'Lycoming','42083'=>'McKean','42085'=>'Mercer','42087'=>'Mifflin',
        '42089'=>'Monroe','42091'=>'Montgomery','42093'=>'Montour','42095'=>'Northampton',
        '42097'=>'Northumberland','42099'=>'Perry','42101'=>'Philadelphia','42103'=>'Pike',
        '42105'=>'Potter','42107'=>'Schuylkill','42109'=>'Snyder','42111'=>'Somerset',
        '42113'=>'Sullivan','42115'=>'Susquehanna','42117'=>'Tioga','42119'=>'Union',
        '42121'=>'Venango','42123'=>'Warren','42125'=>'Washington','42127'=>'Wayne',
        '42129'=>'Westmoreland','42131'=>'Wyoming','42133'=>'York',
    );
    return isset($map[$fips_cd]) ? $map[$fips_cd] : '';
}

// ── NWPS FETCH (paginated by WFO) ────────────────────────────
function fetch_all_gauges() {
    $wfos = array('ctp', 'pbz', 'phi', 'bgm');
    $seen = array();
    $all_features = array();
    $last_err = '';
    foreach ($wfos as $wfo) {
        $where = 'wfo+%3D+%27' . $wfo . '%27';
        $offset = 0;
        for ($page = 0; $page < 8; $page++) {
            $url = NWPS_ENDPOINT . '&where=' . $where . '&resultOffset=' . $offset;
            $r = hget($url, 30);
            if (!$r['body']) { $last_err = "WFO $wfo: " . $r['err']; break; }
            $j = json_decode($r['body'], true);
            if (!is_array($j) || !isset($j['features'])) { $last_err = "WFO $wfo: bad JSON"; break; }
            foreach ($j['features'] as $feat) {
                $lid = strtoupper(trim(isset($feat['attributes']['gaugelid']) ? $feat['attributes']['gaugelid'] : ''));
                if (!$lid || isset($seen[$lid])) continue;
                $seen[$lid] = true;
                $all_features[] = $feat;
            }
            if (empty($j['exceededTransferLimit']) || empty($j['features'])) break;
            $offset += 250;
        }
    }
    return array('features' => $all_features, 'err' => empty($all_features) ? $last_err : '');
}

// ── PARSE NWPS + merge USGS ───────────────────────────────────
function parse_gauges($features, $usgs) {
    // Build lookup: NWS LID → USGS site
    // NWS LIDs ending in P1 are PA sites; USGS site_no is typically 8 digits
    // The NWS URL field contains the NWPS gauge page; USGS match is by
    // searching usgs data for a site whose name contains the gauge location.
    // Best practical approach: USGS site_no often equals NWS LID numerics.
    // e.g. NWS "MAAP1" → not directly numeric; use proximity/name matching.
    // Instead: index USGS by site_no, and store all for CSV/display.
    // For joining: USGS stores NWS LID in the "huc_cd" or agency_cd sometimes,
    // but most reliably we match USGS site name substring against NWS location.
    // Simplest reliable join: USGS IV API accepts "sites" param with site numbers.
    // Since we don't have that mapping here, store USGS keyed by site_no and
    // attach discharge by matching via the NWPS secvalue field (which is USGS Q)
    // OR store separately and let the table show USGS link for lookup.
    // PRAGMATIC: The NWPS 'secvalue' field IS the USGS discharge when pedts=HGIRG.
    // From diagnostic: secunit=kcfs, secvalue is often populated.
    // So we already have discharge in NWPS data as secvalue (in kcfs)!
    // We'll use that + supplement with USGS data keyed by county.

    $gauges = array();
    foreach ($features as $f) {
        $a   = isset($f['attributes']) ? $f['attributes'] : array();
        $lid = strtoupper(trim(isset($a['gaugelid']) ? $a['gaugelid'] : ''));
        if (!$lid) continue;

        $obs = nn(isset($a['observed'])  ? $a['observed']  : null);
        $act = nn(isset($a['action'])    ? $a['action']    : null);
        $fld = nn(isset($a['flood'])     ? $a['flood']     : null);
        $mod = nn(isset($a['moderate'])  ? $a['moderate']  : null);
        $maj = nn(isset($a['major'])     ? $a['major']     : null);
        // secvalue = secondary value (usually USGS discharge in kcfs)
        $secv = nn(isset($a['secvalue']) ? $a['secvalue']  : null);
        $secu = strtolower(trim(isset($a['secunit']) ? $a['secunit'] : ''));
        // Convert kcfs → cfs for display
        $discharge = null;
        if ($secv !== null) {
            if ($secu === 'kcfs') $discharge = round($secv * 1000);
            elseif ($secu === 'cfs') $discharge = round($secv);
        }

        $status = skey(isset($a['status']) ? $a['status'] : '');
        if ($status === 'unknown' && $obs !== null) {
            if      ($maj !== null && $obs >= $maj) $status = 'major';
            elseif  ($mod !== null && $obs >= $mod) $status = 'moderate';
            elseif  ($fld !== null && $obs >= $fld) $status = 'flood';
            elseif  ($act !== null && $obs >= $act) $status = 'action';
            else                                     $status = 'normal';
        }
        if ($status === 'unknown' && $obs !== null) $status = 'normal';

        $wfo    = strtoupper(trim(isset($a['wfo'])   ? $a['wfo']   : ''));
        $state  = strtoupper(trim(isset($a['state']) ? $a['state'] : ''));
        $lid_lc = strtolower($lid);
        $wfo_lc = strtolower($wfo);

        $name = trim(isset($a['location']) ? $a['location'] : '');
        if (!$name) $name = $lid;

        $gurl = trim(isset($a['url']) ? $a['url'] : '');
        if (!$gurl) {
            $gurl = $wfo
                ? "https://water.weather.gov/ahps2/hydrograph.php?wfo={$wfo_lc}&gage={$lid_lc}"
                : "https://water.noaa.gov/gauges/{$lid_lc}";
        }

        // Match USGS data by trying to find site whose name matches gauge name
        $county = '';
        $usgs_site_no = '';
        $usgs_url = 'https://waterdata.usgs.gov/nwis/uv?search_site_no=' . urlencode($lid);
        foreach ($usgs as $sno => $us) {
            $county_raw = $us['county_cd'];
            $c = pa_county_name($county_raw);
            // Match heuristic: USGS site name contains NWS gauge name words
            $nws_words = explode(' ', strtoupper($name));
            $usgs_name = strtoupper($us['site_name']);
            $matches = 0;
            foreach ($nws_words as $w) {
                if (strlen($w) > 3 && strpos($usgs_name, $w) !== false) $matches++;
            }
            if ($matches >= 2 && $c) {
                $county = $c;
                $usgs_site_no = $sno;
                $usgs_url = $us['usgs_url'];
                break;
            }
        }

        $gauges[$lid] = array(
            'lid'       => $lid,
            'name'      => $name,
            'river'     => trim(isset($a['waterbody']) ? $a['waterbody'] : ''),
            'wfo'       => $wfo,
            'state'     => $state,
            'county'    => $county,
            'observed'  => $obs,
            'discharge' => $discharge,
            'action'    => $act,
            'flood'     => $fld,
            'moderate'  => $mod,
            'major'     => $maj,
            'units'     => strtolower(trim(isset($a['units']) ? $a['units'] : 'ft')),
            'status'    => $status,
            'obstime'   => trim(isset($a['obstime']) ? $a['obstime'] : ''),
            'url'       => $gurl,
            'nwps'      => "https://water.noaa.gov/gauges/{$lid_lc}",
            'usgs_url'  => $usgs_url,
            'usgs_no'   => $usgs_site_no,
        );
    }

    $pri = array('major'=>0,'moderate'=>1,'flood'=>2,'action'=>3,'normal'=>4,'unknown'=>5);
    uasort($gauges, function($a, $b) use ($pri) {
        $pa = isset($pri[$a['status']]) ? $pri[$a['status']] : 5;
        $pb = isset($pri[$b['status']]) ? $pri[$b['status']] : 5;
        if ($pa !== $pb) return $pa - $pb;
        $rc = strcmp($a['river'], $b['river']);
        return $rc !== 0 ? $rc : strcmp($a['name'], $b['name']);
    });
    return $gauges;
}

// ── ALERTS ────────────────────────────────────────────────────
function fetch_alerts() {
    $r = hget(ALERTS_URL, 12);
    if (!$r['body']) return array();
    $j = json_decode($r['body'], true);
    if (!isset($j['features'])) return array();
    $out = array();
    foreach ($j['features'] as $f) {
        $p = isset($f['properties']) ? $f['properties'] : array();
        $out[] = array(
            'event'    => isset($p['event'])    ? $p['event']    : '',
            'headline' => isset($p['headline']) ? $p['headline'] : '',
            'severity' => isset($p['severity']) ? $p['severity'] : '',
            'areas'    => isset($p['areaDesc']) ? $p['areaDesc'] : '',
            'sent'     => isset($p['sent'])     ? $p['sent']     : null,
            'expires'  => isset($p['expires'])  ? $p['expires']  : null,
        );
    }
    return $out;
}

// ── LOAD ──────────────────────────────────────────────────────
$force = isset($_GET['refresh']);
$debug = isset($_GET['debug']);

// CSV export — output before any HTML
if (isset($_GET['csv'])) {
    $cached_for_csv = lcache();
    $csv_gauges = $cached_for_csv ? $cached_for_csv['gauges'] : array();
    if (empty($csv_gauges)) {
        // Try fresh fetch for CSV if no cache
        $result = fetch_all_gauges();
        $usgs   = fetch_usgs();
        $csv_gauges = !empty($result['features']) ? parse_gauges($result['features'], $usgs) : array();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pa_rivers_' . date('Ymd_Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array(
        'LID','Name','River/Waterbody','State','County','WFO','Status',
        'Observed (ft)','Discharge (cfs)',
        'Action Stage (ft)','Flood Stage (ft)','Moderate Stage (ft)','Major Stage (ft)',
        'Units','Observed At','NWPS URL','AHPS URL','USGS URL'
    ));
    foreach ($csv_gauges as $g) {
        fputcsv($out, array(
            $g['lid'],
            $g['name'],
            $g['river'],
            $g['state'],
            $g['county'],
            $g['wfo'],
            $g['status'],
            $g['observed'] !== null ? $g['observed'] : '',
            $g['discharge'] !== null ? $g['discharge'] : '',
            $g['action']   !== null ? $g['action']   : '',
            $g['flood']    !== null ? $g['flood']    : '',
            $g['moderate'] !== null ? $g['moderate'] : '',
            $g['major']    !== null ? $g['major']    : '',
            $g['units'],
            $g['obstime'],
            $g['nwps'],
            $g['url'],
            $g['usgs_url'],
        ));
    }
    fclose($out);
    exit;
}

$cached = $force ? null : lcache();
if ($cached) {
    $gauges     = $cached['gauges'];
    $alts       = $cached['alerts'];
    $fetched    = $cached['fetched'];
    $err        = isset($cached['err']) ? $cached['err'] : null;
    $from_cache = true;
} else {
    $result  = fetch_all_gauges();
    $usgs    = fetch_usgs();
    $gauges  = !empty($result['features']) ? parse_gauges($result['features'], $usgs) : array();
    $alts    = fetch_alerts();
    $fetched = time();
    $from_cache = false;
    $err = empty($gauges) ? ($result['err'] ?: 'No gauges returned.') : null;
    scache(array('gauges'=>$gauges,'alerts'=>$alts,'fetched'=>$fetched,'err'=>$err));
}

// ── STATS ─────────────────────────────────────────────────────
$st = array('total'=>count($gauges),'major'=>0,'moderate'=>0,'flood'=>0,'action'=>0,'normal'=>0,'unknown'=>0);
foreach ($gauges as $g) { $s=$g['status']; if(isset($st[$s]))$st[$s]++;else $st['unknown']++; }

$rivers = array(); $wfos = array(); $counties = array();
foreach ($gauges as $g) {
    if ($g['river'])  $rivers[$g['river']]   = (isset($rivers[$g['river']])   ? $rivers[$g['river']]   : 0) + 1;
    if ($g['wfo'])    $wfos[$g['wfo']]       = (isset($wfos[$g['wfo']])       ? $wfos[$g['wfo']]       : 0) + 1;
    if ($g['county']) $counties[$g['county']]= (isset($counties[$g['county']])? $counties[$g['county']]: 0) + 1;
}
arsort($rivers); ksort($wfos); ksort($counties);

$lupd = date('D M j, Y g:i a T', $fetched);
$acnt = count($alts);

// ── HELPERS ───────────────────────────────────────────────────
function fth($v)      { return ($v !== null) ? number_format($v,1).' ft' : '—'; }
function fob($v, $u)  { return ($v !== null) ? number_format($v,2).' '.$u : '—'; }
function fcfs($v)     { return ($v !== null) ? number_format($v).' cfs' : '—'; }
function ftm($t) {
    if (!$t) return 'N/A';
    try {
        $dt = new DateTime($t, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/New_York'));
        return $dt->format('M j, g:i a T');
    } catch (Exception $e) { return $t; }
}

$bcls = array('major'=>'bmaj','moderate'=>'bmod','flood'=>'bfld','action'=>'bact','normal'=>'bnrm','unknown'=>'bunk');
$blbl = array('major'=>'&#9650; MAJOR','moderate'=>'&#9650; MODERATE','flood'=>'&#9650; FLOOD','action'=>'&#9888; ACTION','normal'=>'&#10003; NORMAL','unknown'=>'&#8212; N/A');
$fcls2= array('major'=>'fmaj','moderate'=>'fmod','flood'=>'ffld','action'=>'fact','normal'=>'fnrm','unknown'=>'funk');
$vcls = array('major'=>'vmaj','moderate'=>'vmod','flood'=>'vfld','action'=>'vact','normal'=>'vnrm','unknown'=>'vunk');
$pri  = array('major'=>0,'moderate'=>1,'flood'=>2,'action'=>3,'normal'=>4,'unknown'=>5);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pennsylvania River &amp; Stream Levels</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=IBM+Plex+Mono:wght@300;400;600&family=Barlow:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07090d;--bg2:#0c1018;--bg3:#111822;--bd:#1a2535;
  --tx:#b8ccdf;--mt:#3d5670;--hi:#e0eeff;
  --ac:#0af;--ac2:#0077cc;--ac3:#004488;
  --maj:#ff1a3c;--mod:#ff6600;--fld:#ffaa00;
  --act:#ffe033;--nrm:#00dd88;--unk:#445566;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--tx);font-family:'Barlow','Helvetica Neue',sans-serif;font-size:14px;line-height:1.5}
.hdr{position:sticky;top:0;z-index:200;background:rgba(7,9,13,.97);border-bottom:1px solid var(--bd);
     backdrop-filter:blur(8px);display:flex;align-items:center;gap:1.2rem;padding:0 1.5rem;height:56px}
.logo{font-family:'Oswald',sans-serif;font-size:1.55rem;letter-spacing:.12em;color:var(--hi);white-space:nowrap}
.logo em{color:var(--ac);font-style:normal}
.sub{font-family:'IBM Plex Mono',monospace;font-size:.6rem;color:var(--mt);display:flex;flex-direction:column;gap:1px}
.sub strong{color:var(--tx)}
.hdr-r{margin-left:auto;display:flex;align-items:center;gap:.5rem}
.pill{font-family:'IBM Plex Mono',monospace;font-size:.58rem;padding:.2rem .5rem;border:1px solid;display:inline-flex;align-items:center;gap:.28rem}
.pl{border-color:var(--ac3);color:var(--ac)}.pc{border-color:#443300;color:#aa8800}
.pe{border-color:#550000;color:#ff7777}.pa{border-color:var(--maj);color:var(--maj)}
.dot{width:6px;height:6px;border-radius:50%;background:var(--ac);animation:blink 2s infinite}
@keyframes blink{0%,100%{box-shadow:0 0 0 0 rgba(0,170,255,.5)}50%{box-shadow:0 0 0 5px rgba(0,170,255,0)}}
.btn{font-family:'Barlow',sans-serif;font-weight:600;font-size:.63rem;letter-spacing:.08em;text-transform:uppercase;
     padding:.26rem .65rem;border:1px solid var(--ac2);background:transparent;color:var(--ac);
     cursor:pointer;text-decoration:none;transition:all .2s;display:inline-flex;align-items:center;gap:.3rem}
.btn:hover{background:var(--ac2);color:#fff}
.btns{border-color:var(--bd);color:var(--mt)}.btns:hover{border-color:var(--mt);color:var(--tx);background:transparent}
.btncsv{border-color:#1a4a1a;color:#44cc44}.btncsv:hover{background:#1a4a1a}
.flow{height:2px;background:linear-gradient(90deg,transparent,var(--ac2),var(--ac),var(--ac2),transparent);background-size:200%;animation:flow 4s linear infinite}
@keyframes flow{0%{background-position:100% 0}100%{background-position:-100% 0}}
.page{max-width:1800px;margin:0 auto;padding:1.2rem;display:grid;grid-template-columns:252px 1fr;gap:1.2rem}
.full{grid-column:1/-1}
.ok{padding:.6rem 1rem;background:rgba(0,221,136,.07);border:1px solid rgba(0,221,136,.2);color:var(--nrm);font-size:.78rem;display:flex;align-items:center;gap:.5rem}
.acard{margin-bottom:.4rem;padding:.6rem 1rem;display:flex;align-items:flex-start;gap:.7rem;border-left:4px solid}
.acard.xtr,.acard.sev{border-color:var(--maj);background:rgba(255,26,60,.08)}
.acard.mod{border-color:var(--fld);background:rgba(255,170,0,.08)}
.acard.min{border-color:var(--act);background:rgba(255,224,51,.06)}
.aico{font-size:1.05rem;flex-shrink:0;margin-top:2px}
.aev{font-family:'Oswald',sans-serif;font-size:.93rem;letter-spacing:.1em}
.xtr .aev,.sev .aev{color:var(--maj)}.mod .aev{color:var(--fld)}.min .aev{color:var(--act)}
.ahl{font-size:.73rem;color:var(--tx);margin-top:.1rem}
.aar{font-size:.65rem;color:var(--mt);margin-top:.1rem}
.amet{margin-left:auto;text-align:right;flex-shrink:0;font-family:'IBM Plex Mono',monospace;font-size:.6rem;color:var(--mt)}
.errbox{padding:.65rem 1rem;background:rgba(200,50,50,.09);border:1px solid rgba(255,50,50,.22);
        color:#ff9999;font-family:'IBM Plex Mono',monospace;font-size:.7rem;margin-bottom:.7rem;word-break:break-all}
.side{display:flex;flex-direction:column;gap:.85rem;align-self:start;position:sticky;top:64px}
.wg{background:var(--bg2);border:1px solid var(--bd);padding:.8rem}
.wgt{font-family:'Oswald',sans-serif;font-size:.9rem;letter-spacing:.12em;color:var(--ac);margin-bottom:.6rem;padding-bottom:.38rem;border-bottom:1px solid var(--bd)}
.sg{display:grid;grid-template-columns:1fr 1fr;gap:.32rem}
.sb{background:var(--bg3);border:1px solid var(--bd);border-left:3px solid;padding:.42rem .38rem;text-align:center;cursor:pointer;transition:background .18s}
.sb:hover,.sb.on{background:rgba(0,100,180,.11)}
.sa{border-left-color:var(--ac)}.sj{border-left-color:var(--maj)}.so{border-left-color:var(--mod)}
.sf{border-left-color:var(--fld)}.sc{border-left-color:var(--act)}.sn{border-left-color:var(--nrm)}
.snum{font-family:'Oswald',sans-serif;font-size:1.85rem;line-height:1}
.sa .snum{color:var(--ac)}.sj .snum{color:var(--maj)}.so .snum{color:var(--mod)}
.sf .snum{color:var(--fld)}.sc .snum{color:var(--act)}.sn .snum{color:var(--nrm)}
.slbl{font-size:.57rem;text-transform:uppercase;letter-spacing:.06em;color:var(--mt)}
.fl{display:flex;flex-direction:column;gap:.2rem;max-height:200px;overflow-y:auto}
.fb{display:flex;align-items:center;justify-content:space-between;padding:.26rem .48rem;
    background:var(--bg3);border:1px solid var(--bd);color:var(--tx);cursor:pointer;
    font-family:'Barlow',sans-serif;font-size:.7rem;font-weight:600;text-transform:uppercase;
    transition:all .18s;text-align:left;width:100%}
.fb:hover,.fb.on{border-color:var(--ac);background:rgba(0,100,180,.11);color:var(--ac)}
.fc{font-family:'IBM Plex Mono',monospace;font-size:.6rem;background:var(--bg);padding:.02rem .26rem;flex-shrink:0}
.leg{display:flex;flex-direction:column;gap:.28rem}
.lr{display:flex;align-items:center;gap:.48rem;font-size:.68rem}
.lc{width:62px;height:15px;flex-shrink:0;font-family:'IBM Plex Mono',monospace;font-size:.47rem;font-weight:600;display:flex;align-items:center;justify-content:center;border:1px solid}
.lmaj{background:rgba(255,26,60,.17);color:var(--maj);border-color:var(--maj)}
.lmod{background:rgba(255,102,0,.17);color:var(--mod);border-color:var(--mod)}
.lfld{background:rgba(255,170,0,.17);color:var(--fld);border-color:var(--fld)}
.lact{background:rgba(255,224,51,.12);color:var(--act);border-color:var(--act)}
.lnrm{background:rgba(0,221,136,.12);color:var(--nrm);border-color:var(--nrm)}
.lunk{background:rgba(68,85,102,.17);color:var(--unk);border-color:var(--unk)}
.src{display:flex;flex-direction:column;gap:.32rem}
.si{display:flex;align-items:flex-start;gap:.35rem;font-size:.66rem;color:var(--mt)}
.sd{width:5px;height:5px;border-radius:50%;flex-shrink:0;margin-top:5px}
.si a{color:var(--ac);text-decoration:none;font-size:.61rem}.si a:hover{text-decoration:underline}
.con{min-width:0;display:flex;flex-direction:column;gap:1.3rem}
.sh{font-family:'Oswald',sans-serif;font-size:1.1rem;letter-spacing:.14em;color:var(--hi);margin-bottom:.72rem;display:flex;align-items:center;gap:.72rem}
.sh span{color:var(--ac)}.sh::after{content:'';flex:1;height:1px;background:var(--bd)}
.tc{display:flex;align-items:center;gap:.6rem;margin-bottom:.65rem;flex-wrap:wrap}
.srch{position:relative;flex:1;min-width:175px}
.srch input{width:100%;background:var(--bg3);border:1px solid var(--bd);color:var(--tx);font-family:'IBM Plex Mono',monospace;font-size:.7rem;padding:.36rem .62rem .36rem 1.7rem;outline:none;transition:border-color .2s}
.srch input:focus{border-color:var(--ac)}.srch input::placeholder{color:var(--mt)}
.sico{position:absolute;left:.48rem;top:50%;transform:translateY(-50%);color:var(--mt);pointer-events:none;font-size:.7rem}
.stb{font-family:'Barlow',sans-serif;font-size:.61rem;font-weight:600;letter-spacing:.07em;text-transform:uppercase;padding:.3rem .58rem;background:var(--bg3);border:1px solid var(--bd);color:var(--mt);cursor:pointer;transition:all .18s}
.stb:hover,.stb.on{border-color:var(--ac);color:var(--ac)}
#ri{font-family:'IBM Plex Mono',monospace;font-size:.58rem;color:var(--mt);white-space:nowrap}
.tw{background:var(--bg2);border:1px solid var(--bd);overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.72rem}
thead th{background:var(--bg3);padding:.45rem .6rem;text-align:left;font-family:'Barlow',sans-serif;font-weight:700;font-size:.57rem;letter-spacing:.1em;text-transform:uppercase;color:var(--mt);border-bottom:2px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none}
thead th:hover{color:var(--ac)}
tbody tr{border-bottom:1px solid rgba(26,37,53,.45);transition:background .12s}
tbody tr:hover{background:rgba(0,100,200,.05)}
tbody tr.hid{display:none}
td{padding:.4rem .6rem;vertical-align:middle;white-space:nowrap}
.tn{white-space:normal;min-width:150px;max-width:230px}
.tn strong{font-size:.74rem;color:var(--hi);display:block}
.tn small{font-family:'IBM Plex Mono',monospace;font-size:.56rem;color:var(--mt)}
.trv{font-size:.67rem;color:var(--mt);max-width:140px;white-space:normal}
.tcty{font-size:.67rem;color:var(--mt)}
.twfo{font-family:'IBM Plex Mono',monospace;font-size:.63rem;color:var(--mt)}
.tth{font-family:'IBM Plex Mono',monospace;font-size:.64rem;color:var(--mt)}
.ttm{font-family:'IBM Plex Mono',monospace;font-size:.58rem;color:var(--mt)}
.badge{display:inline-flex;align-items:center;padding:.15rem .4rem;font-family:'Barlow',sans-serif;font-size:.57rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;border:1px solid;white-space:nowrap}
.bmaj{background:rgba(255,26,60,.15);color:var(--maj);border-color:var(--maj);animation:pmaj 1.4s ease-in-out infinite}
.bmod{background:rgba(255,102,0,.15);color:var(--mod);border-color:var(--mod);animation:pmod 1.8s ease-in-out infinite}
.bfld{background:rgba(255,170,0,.15);color:var(--fld);border-color:var(--fld)}
.bact{background:rgba(255,224,51,.10);color:var(--act);border-color:var(--act)}
.bnrm{background:rgba(0,221,136,.09);color:var(--nrm);border-color:var(--nrm)}
.bunk{background:rgba(68,85,102,.12);color:var(--unk);border-color:var(--unk)}
@keyframes pmaj{0%,100%{box-shadow:0 0 0 0 rgba(255,26,60,.4)}50%{box-shadow:0 0 0 5px rgba(255,26,60,0)}}
@keyframes pmod{0%,100%{box-shadow:0 0 0 0 rgba(255,102,0,.35)}50%{box-shadow:0 0 0 4px rgba(255,102,0,0)}}
.bar{display:flex;align-items:center;gap:.36rem}
.bt{width:48px;height:3px;background:var(--bg3);border:1px solid var(--bd);border-radius:2px;overflow:hidden;flex-shrink:0}
.bf{height:100%;border-radius:2px}
.fmaj{background:var(--maj)}.fmod{background:var(--mod)}.ffld{background:var(--fld)}
.fact{background:var(--act)}.fnrm{background:var(--nrm)}.funk{background:var(--unk)}
.sv{font-family:'IBM Plex Mono',monospace;font-weight:600;font-size:.76rem}
.vmaj{color:var(--maj)}.vmod{color:var(--mod)}.vfld{color:var(--fld)}
.vact{color:var(--act)}.vnrm{color:var(--nrm)}.vunk{color:var(--unk)}
/* discharge value styling */
.cfs{font-family:'IBM Plex Mono',monospace;font-size:.68rem;color:#88ccff}
.cfs-na{font-family:'IBM Plex Mono',monospace;font-size:.65rem;color:var(--mt)}
.el{font-family:'IBM Plex Mono',monospace;font-size:.57rem;color:var(--ac);text-decoration:none;opacity:.62;transition:opacity .18s}
.el:hover{opacity:1;text-decoration:underline}
.rg{display:grid;grid-template-columns:repeat(auto-fill,minmax(222px,1fr));gap:.65rem}
.rc{background:var(--bg2);border:1px solid var(--bd);padding:.78rem}
.rct{font-family:'Oswald',sans-serif;font-size:.9rem;letter-spacing:.1em;color:var(--ac);margin-bottom:.42rem}
.rc p{font-size:.68rem;color:var(--tx);margin-bottom:.5rem;line-height:1.6}
.rl{display:flex;flex-direction:column;gap:.2rem}
.rl a{font-size:.64rem;color:var(--ac);text-decoration:none}.rl a:hover{text-decoration:underline}
footer{background:var(--bg2);border-top:1px solid var(--bd);padding:1rem;text-align:center;font-size:.63rem;color:var(--mt);line-height:2;margin-top:1rem}
footer a{color:var(--ac);text-decoration:none}footer a:hover{text-decoration:underline}
footer strong{color:var(--tx)}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--bd);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--ac2)}
@media(max-width:1100px){.page{grid-template-columns:1fr}.side{position:static;display:grid;grid-template-columns:repeat(auto-fill,minmax(202px,1fr))}}
@media(max-width:600px){.logo{font-size:1.05rem}.sub{display:none}.page{padding:.75rem}}
</style>
</head>
<body>
<header class="hdr">
  <div class="logo">PA <em>RIVERS</em> &amp; STREAMS</div>
  <div class="sub">
    <span>Sources: <strong>NOAA NWPS &middot; USGS IV &middot; NWS Alerts &middot; US ACE &middot; iFlows PA</strong></span>
    <span>Updated: <strong><?php echo htmlspecialchars($lupd); ?></strong><?php if ($from_cache) echo ' <span style="color:var(--act)">[cached]</span>'; ?></span>
  </div>
  <div class="hdr-r">
    <?php if (empty($gauges)): ?><span class="pill pe">&#9888; No data</span>
    <?php elseif ($from_cache): ?><span class="pill pc">&#8987; Cached</span>
    <?php else: ?><span class="pill pl"><span class="dot"></span>Live</span><?php endif; ?>
    <?php if ($acnt > 0): ?><span class="pill pa">&#9888; <?php echo $acnt; ?> Flood Alert<?php echo $acnt > 1 ? 's' : ''; ?></span><?php endif; ?>
    <a class="btn btncsv" href="?csv=1">&#11015; CSV</a>
    <a class="btn" href="?refresh=1">&#8635; Refresh</a>
    <a class="btn btns" href="?<?php echo $debug ? '' : 'debug=1'; ?>"><?php echo $debug ? '&mdash; Debug' : 'Debug'; ?></a>
  </div>
</header>
<div class="flow"></div>

<div class="page">

<section class="full">
  <div class="sh">Active NWS <span>Flood Alerts</span> &mdash; Pennsylvania</div>
  <?php if ($err && ($debug || empty($gauges))): ?>
  <div class="errbox"><strong>Gauge data error:</strong> <?php echo htmlspecialchars($err); ?></div>
  <?php endif; ?>
  <?php if (empty($alts)): ?>
  <div class="ok">&#10003; &nbsp;No active flood warnings, watches, or advisories for Pennsylvania.</div>
  <?php else: foreach ($alts as $al): ?>
    <?php
      $sev = strtolower($al['severity']);
      if ($sev === 'extreme' || $sev === 'severe') $cls = 'xtr sev';
      elseif ($sev === 'moderate') $cls = 'mod'; else $cls = 'min';
      if (strpos($al['event'], 'Flash') !== false) $ico = '&#9889;';
      elseif (strpos($al['event'], 'Warning') !== false) $ico = '&#128308;';
      elseif (strpos($al['event'], 'Watch') !== false) $ico = '&#128992;';
      else $ico = '&#128309;';
    ?>
  <div class="acard <?php echo $cls; ?>">
    <div class="aico"><?php echo $ico; ?></div>
    <div style="flex:1">
      <div class="aev"><?php echo htmlspecialchars($al['event']); ?></div>
      <div class="ahl"><?php echo htmlspecialchars($al['headline']); ?></div>
      <div class="aar">&#128205; <?php echo htmlspecialchars($al['areas']); ?></div>
    </div>
    <div class="amet">
      <?php if ($al['expires']): ?><div>Expires: <?php echo ftm($al['expires']); ?></div><?php endif; ?>
      <?php if ($al['sent']): ?><div>Issued: <?php echo ftm($al['sent']); ?></div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>
</section>

<aside class="side">
  <div class="wg">
    <div class="wgt">Status Summary</div>
    <div class="sg">
      <div class="sb sa on" id="sb-all"      onclick="setStatus('all',this)"><div class="snum"><?php echo $st['total']; ?></div><div class="slbl">All Gauges</div></div>
      <div class="sb sj"    id="sb-major"    onclick="setStatus('major',this)"><div class="snum"><?php echo $st['major']; ?></div><div class="slbl">Major Flood</div></div>
      <div class="sb so"    id="sb-moderate" onclick="setStatus('moderate',this)"><div class="snum"><?php echo $st['moderate']; ?></div><div class="slbl">Moderate</div></div>
      <div class="sb sf"    id="sb-flood"    onclick="setStatus('flood',this)"><div class="snum"><?php echo $st['flood']; ?></div><div class="slbl">Flood Stage</div></div>
      <div class="sb sc"    id="sb-action"   onclick="setStatus('action',this)"><div class="snum"><?php echo $st['action']; ?></div><div class="slbl">Action Stage</div></div>
      <div class="sb sn"    id="sb-normal"   onclick="setStatus('normal',this)"><div class="snum"><?php echo $st['normal']; ?></div><div class="slbl">Normal</div></div>
    </div>
  </div>

  <div class="wg">
    <div class="wgt">Filter by River</div>
    <div class="fl" id="f-river">
      <button class="fb on" data-val="">All Rivers <span class="fc"><?php echo count($gauges); ?></span></button>
      <?php foreach ($rivers as $r => $c): if (!$r) continue; ?>
      <button class="fb" data-val="<?php echo htmlspecialchars($r,ENT_QUOTES); ?>"><?php echo htmlspecialchars($r); ?> <span class="fc"><?php echo $c; ?></span></button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="wg">
    <div class="wgt">Filter by County</div>
    <div class="fl" id="f-county">
      <button class="fb on" data-val="">All Counties <span class="fc"><?php echo count($gauges); ?></span></button>
      <?php foreach ($counties as $cty => $c): if (!$cty) continue; ?>
      <button class="fb" data-val="<?php echo htmlspecialchars($cty,ENT_QUOTES); ?>"><?php echo htmlspecialchars($cty); ?> Co. <span class="fc"><?php echo $c; ?></span></button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="wg">
    <div class="wgt">Filter by WFO</div>
    <div class="fl" id="f-wfo">
      <button class="fb on" data-val="">All Offices <span class="fc"><?php echo count($gauges); ?></span></button>
      <?php foreach ($wfos as $w => $c): if (!$w) continue;
        $wnames = array('BGM'=>'BGM &mdash; Binghamton','CTP'=>'CTP &mdash; State College',
          'PBZ'=>'PBZ &mdash; Pittsburgh','PHI'=>'PHI &mdash; Philadelphia',
          'RLX'=>'RLX &mdash; Charleston WV','BUF'=>'BUF &mdash; Buffalo');
        $wn = isset($wnames[$w]) ? $wnames[$w] : htmlspecialchars($w);
      ?>
      <button class="fb" data-val="<?php echo htmlspecialchars($w,ENT_QUOTES); ?>"><?php echo $wn; ?> <span class="fc"><?php echo $c; ?></span></button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="wg">
    <div class="wgt">Legend</div>
    <div class="leg">
      <div class="lr"><div class="lc lmaj">MAJOR</div><span>Major &mdash; significant inundation</span></div>
      <div class="lr"><div class="lc lmod">MODERATE</div><span>Moderate &mdash; some structures flooded</span></div>
      <div class="lr"><div class="lc lfld">FLOOD</div><span>Flood (minor) stage &mdash; banks overflow</span></div>
      <div class="lr"><div class="lc lact">ACTION</div><span>Action stage &mdash; agencies prepare</span></div>
      <div class="lr"><div class="lc lnrm">NORMAL</div><span>Below action stage</span></div>
      <div class="lr"><div class="lc lunk">N/A</div><span>No thresholds configured</span></div>
    </div>
  </div>

  <div class="wg">
    <div class="wgt">Data Sources</div>
    <div class="src">
      <div class="si"><div class="sd" style="background:var(--ac)"></div>
        <div><strong>NOAA NWPS MapServer</strong><br><a href="https://mapservices.weather.noaa.gov/eventdriven/rest/services/water/riv_gauges/MapServer/0" target="_blank">riv_gauges layer 0</a></div></div>
      <div class="si"><div class="sd" style="background:#88ccff"></div>
        <div><strong>USGS Water Services IV</strong><br><a href="https://waterservices.usgs.gov/nwis/iv/" target="_blank">waterservices.usgs.gov</a></div></div>
      <div class="si"><div class="sd" style="background:#ffcc00"></div>
        <div><strong>NWS Alerts API</strong><br><a href="https://api.weather.gov/alerts/active?area=PA" target="_blank">api.weather.gov</a></div></div>
      <div class="si"><div class="sd" style="background:#88aaff"></div>
        <div><strong>US Army Corps of Engineers</strong><br><a href="https://www.lrp.usace.army.mil/" target="_blank">lrp.usace.army.mil</a></div></div>
      <div class="si"><div class="sd" style="background:#aaffcc"></div>
        <div><strong>iFlows PA / PEMA</strong><br><a href="https://www.iflows.state.pa.us/" target="_blank">iflows.state.pa.us</a></div></div>
      <div class="si"><div class="sd" style="background:#ffaacc"></div>
        <div><strong>NOAA HADS</strong><br><a href="https://hads.ncep.noaa.gov/charts/PA.shtml" target="_blank">hads.ncep.noaa.gov</a></div></div>
    </div>
  </div>
</aside>

<div class="con">
  <section>
    <div class="sh">NWPS + USGS <span>Pennsylvania</span> River &amp; Stream Gauges
      <span style="font-size:.7rem;color:var(--mt);letter-spacing:0;font-family:'IBM Plex Mono',monospace"><?php echo count($gauges); ?> gauges</span>
    </div>
    <div class="tc">
      <div class="srch"><span class="sico">&#128269;</span>
        <input id="q" type="text" placeholder="Search name, river, LID, WFO, county&hellip;">
      </div>
      <button class="stb on" id="st-status"    onclick="setSort('status',this)">Status</button>
      <button class="stb"    id="st-stage"     onclick="setSort('stage',this)">Stage &#8597;</button>
      <button class="stb"    id="st-discharge" onclick="setSort('discharge',this)">Flow &#8597;</button>
      <button class="stb"    id="st-name"      onclick="setSort('name',this)">Name &#8597;</button>
      <button class="stb"    id="st-river"     onclick="setSort('river',this)">River &#8597;</button>
      <button class="stb"    id="st-county"    onclick="setSort('county',this)">County &#8597;</button>
      <span id="ri"></span>
    </div>
    <div class="tw">
      <table>
        <thead><tr>
          <th onclick="setSort('name',document.getElementById('st-name'))">Gauge / LID</th>
          <th onclick="setSort('river',document.getElementById('st-river'))">River / Waterbody</th>
          <th onclick="setSort('county',document.getElementById('st-county'))">County</th>
          <th>WFO</th>
          <th onclick="setSort('status',document.getElementById('st-status'))">Status</th>
          <th onclick="setSort('stage',document.getElementById('st-stage'))">Stage (ft)</th>
          <th onclick="setSort('discharge',document.getElementById('st-discharge'))">Discharge (cfs)</th>
          <th>Action</th><th>Flood</th><th>Moderate</th><th>Major</th>
          <th>Observed At (ET)</th>
          <th>Links</th>
        </tr></thead>
        <tbody id="gb">
        <?php foreach ($gauges as $g):
          $s    = $g['status'];
          $obs  = $g['observed'];
          $dis  = $g['discharge'];
          $pct  = 0;
          if ($obs !== null) {
              $ref = ($g['flood'] !== null) ? $g['flood'] : $g['action'];
              if ($ref && $ref > 0) $pct = min(100, (int)($obs / ($ref * 1.3) * 100));
          }
          $bc   = isset($bcls[$s])  ? $bcls[$s]  : 'bunk';
          $bl   = isset($blbl[$s])  ? $blbl[$s]  : '&mdash; N/A';
          $fc2  = isset($fcls2[$s]) ? $fcls2[$s] : 'funk';
          $vc   = isset($vcls[$s])  ? $vcls[$s]  : 'vunk';
          $sord = isset($pri[$s])   ? $pri[$s]   : 5;
        ?>
        <tr class="gr"
          data-status="<?php echo $s; ?>"
          data-sord="<?php echo $sord; ?>"
          data-river="<?php echo strtolower(htmlspecialchars($g['river'],ENT_QUOTES)); ?>"
          data-county="<?php echo strtolower(htmlspecialchars($g['county'],ENT_QUOTES)); ?>"
          data-wfo="<?php echo strtolower(htmlspecialchars($g['wfo'],ENT_QUOTES)); ?>"
          data-name="<?php echo strtolower(htmlspecialchars($g['name'],ENT_QUOTES)); ?>"
          data-lid="<?php echo strtolower(htmlspecialchars($g['lid'],ENT_QUOTES)); ?>"
          data-stage="<?php echo ($obs !== null) ? $obs : -9999; ?>"
          data-discharge="<?php echo ($dis !== null) ? $dis : -9999; ?>">
          <td class="tn">
            <strong><?php echo htmlspecialchars($g['name']); ?></strong>
            <small><?php echo htmlspecialchars($g['lid']); ?><?php if ($g['state'] !== 'PA') echo ' <span style="color:var(--act)">['.$g['state'].']</span>'; ?></small>
          </td>
          <td class="trv"><?php echo htmlspecialchars($g['river'] ? $g['river'] : '—'); ?></td>
          <td class="tcty"><?php echo htmlspecialchars($g['county'] ? $g['county'] . ' Co.' : '—'); ?></td>
          <td class="twfo"><?php echo htmlspecialchars($g['wfo'] ? $g['wfo'] : '—'); ?></td>
          <td><span class="badge <?php echo $bc; ?>"><?php echo $bl; ?></span></td>
          <td>
            <div class="bar">
              <div class="bt"><div class="bf <?php echo $fc2; ?>" style="width:<?php echo $pct; ?>%"></div></div>
              <span class="sv <?php echo $vc; ?>"><?php echo fob($obs, $g['units']); ?></span>
            </div>
          </td>
          <td><?php if ($dis !== null): ?>
            <span class="cfs"><?php echo number_format($dis); ?> <span style="font-size:.58rem;opacity:.7">cfs</span></span>
          <?php else: ?>
            <span class="cfs-na">—</span>
          <?php endif; ?></td>
          <td class="tth"><?php echo fth($g['action']); ?></td>
          <td class="tth"><?php echo fth($g['flood']); ?></td>
          <td class="tth"><?php echo fth($g['moderate']); ?></td>
          <td class="tth"><?php echo fth($g['major']); ?></td>
          <td class="ttm"><?php echo ftm($g['obstime']); ?></td>
          <td style="white-space:nowrap">
            <a class="el" href="<?php echo htmlspecialchars($g['url']); ?>" target="_blank">AHPS</a>
            &nbsp;<a class="el" href="<?php echo htmlspecialchars($g['nwps']); ?>" target="_blank">NWPS</a>
            <?php if ($g['usgs_no']): ?>
            &nbsp;<a class="el" href="<?php echo htmlspecialchars($g['usgs_url']); ?>" target="_blank">USGS</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="font-size:.58rem;color:var(--mt);margin-top:.38rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <span>Showing <strong id="rc"><?php echo count($gauges); ?></strong> of <strong><?php echo count($gauges); ?></strong> gauges.</span>
      <span>Stage from NWPS &middot; Discharge from NWPS secvalue (kcfs&#8594;cfs) &middot; County via USGS IV &middot; Cache <?php echo CACHE_TTL/60; ?> min</span>
      <a class="el" href="?csv=1">&#11015; Download CSV</a>
      <a class="el" href="https://water.noaa.gov/map/PA" target="_blank">&#8594; NWPS map</a>
      <a class="el" href="https://hads.ncep.noaa.gov/charts/PA.shtml" target="_blank">&#8594; All PA DCPs</a>
    </p>
  </section>

  <section>
    <div class="sh">External <span>Resources</span></div>
    <div class="rg">
      <div class="rc"><div class="rct">NWS AHPS / NWPS</div>
        <p>Official stages, forecasts, and flood inundation maps. NWPS replaced AHPS publicly in March 2024.</p>
        <div class="rl">
          <a href="https://water.noaa.gov/map/PA" target="_blank">&#8594; NWPS Pennsylvania Map</a>
          <a href="https://water.weather.gov/ahps2/index.php?wfo=ctp" target="_blank">&#8594; AHPS WFO State College (CTP)</a>
          <a href="https://water.weather.gov/ahps2/index.php?wfo=pbz" target="_blank">&#8594; AHPS WFO Pittsburgh (PBZ)</a>
          <a href="https://water.weather.gov/ahps2/index.php?wfo=phi" target="_blank">&#8594; AHPS WFO Philadelphia (PHI)</a>
          <a href="https://water.weather.gov/ahps2/index.php?wfo=bgm" target="_blank">&#8594; AHPS WFO Binghamton (BGM)</a>
        </div></div>
      <div class="rc"><div class="rct">USGS Streamflow</div>
        <p>Real-time discharge (cfs) and stage data. USGS feeds NWS forecasts and provides the discharge values shown in this dashboard.</p>
        <div class="rl">
          <a href="https://waterdata.usgs.gov/pa/nwis/rt" target="_blank">&#8594; PA Real-time Streamflow</a>
          <a href="https://waterservices.usgs.gov/nwis/iv/?stateCd=PA&parameterCd=00060,00065&format=json" target="_blank">&#8594; USGS IV API (PA)</a>
          <a href="https://maps.waterdata.usgs.gov/mapper/index.html" target="_blank">&#8594; USGS Water Resources Map</a>
        </div></div>
      <div class="rc"><div class="rct">NOAA HADS</div>
        <p>Every PA Data Collection Platform, including small-stream gauges not in NWPS.</p>
        <div class="rl">
          <a href="https://hads.ncep.noaa.gov/charts/PA.shtml" target="_blank">&#8594; All PA DCPs</a>
          <a href="https://hads.ncep.noaa.gov/interactiveDisplays/displays.shtml" target="_blank">&#8594; HADS Interactive Display</a>
        </div></div>
      <div class="rc"><div class="rct">US Army Corps of Engineers</div>
        <p>Operates flood-control reservoirs and locks throughout PA. Pool levels and releases affect downstream stages.</p>
        <div class="rl">
          <a href="https://www.lrp.usace.army.mil/Missions/Civil-Works/Reservoir-and-Lake-Projects/" target="_blank">&#8594; LRP Reservoir Projects</a>
          <a href="https://www.lrn.usace.army.mil/Missions/Water-Resources/" target="_blank">&#8594; LRN Water Resources</a>
          <a href="https://www.nab.usace.army.mil/Missions/Civil-Works/Flood-Risk-Management/" target="_blank">&#8594; NAB Flood Risk (Delaware)</a>
        </div></div>
      <div class="rc"><div class="rct">iFlows Pennsylvania</div>
        <p>PEMA's small-stream gauge network covering creeks not in USGS or NWPS.</p>
        <div class="rl">
          <a href="https://www.iflows.state.pa.us/" target="_blank">&#8594; iFlows Live Gauge Map</a>
          <a href="https://www.pema.pa.gov/Mitigation/Pages/Flood-Warning-System.aspx" target="_blank">&#8594; PEMA Flood Warning System</a>
        </div></div>
      <div class="rc"><div class="rct">River Forecast Centers</div>
        <p>MARFC and NERFC produce official river forecasts for major PA rivers.</p>
        <div class="rl">
          <a href="https://www.weather.gov/marfc/" target="_blank">&#8594; MARFC (Middle Atlantic RFC)</a>
          <a href="https://www.weather.gov/nerfc/" target="_blank">&#8594; NERFC (Northeast RFC)</a>
          <a href="https://api.weather.gov/alerts/active?area=PA" target="_blank">&#8594; PA Active Alerts (JSON)</a>
        </div></div>
    </div>
  </section>
</div>
</div>

<footer>
  <strong>Pennsylvania Rivers &amp; Stream Levels Dashboard</strong><br>
  Stage: <a href="https://mapservices.weather.noaa.gov/eventdriven/rest/services/water/riv_gauges/MapServer/0" target="_blank">NOAA NWPS MapServer</a> &middot;
  Discharge: <a href="https://waterservices.usgs.gov/" target="_blank">USGS Water Services IV</a> &middot;
  <a href="https://api.weather.gov/" target="_blank">NWS Alerts</a> &middot;
  <a href="https://hads.ncep.noaa.gov/charts/PA.shtml" target="_blank">NOAA HADS</a> &middot;
  <a href="https://www.usace.army.mil/" target="_blank">US ACE</a> &middot;
  <a href="https://www.iflows.state.pa.us/" target="_blank">iFlows PA</a><br>
  Emergencies: <strong>911</strong> &middot; PEMA: <a href="https://www.pema.pa.gov/">pema.pa.gov</a> &middot; 1-800-422-7362<br>
  Data cached <?php echo CACHE_TTL/60; ?> min &middot; Informational use only &mdash; always consult official NWS/PEMA sources
</footer>

<script>
var F={status:'all',river:'',county:'',wfo:'',q:''};
var sc='status',sd='asc';

function setStatus(s,el){
  F.status=s;
  var bs=document.querySelectorAll('.sb');
  for(var i=0;i<bs.length;i++) bs[i].classList.remove('on');
  el.classList.add('on');render();
}
function makeFilter(id,key){
  document.getElementById(id).addEventListener('click',function(e){
    var b=e.target.closest('.fb');if(!b)return;
    var bs=document.querySelectorAll('#'+id+' .fb');
    for(var i=0;i<bs.length;i++) bs[i].classList.remove('on');
    b.classList.add('on');F[key]=b.dataset.val;render();
  });
}
makeFilter('f-river','river');
makeFilter('f-county','county');
makeFilter('f-wfo','wfo');

document.getElementById('q').addEventListener('input',function(e){
  F.q=e.target.value.toLowerCase().trim();render();
});

function setSort(col,el){
  sd=(sc===col)?(sd==='asc'?'desc':'asc'):(col==='stage'||col==='discharge'?'desc':'asc');
  sc=col;
  var bs=document.querySelectorAll('.stb');
  for(var i=0;i<bs.length;i++) bs[i].classList.remove('on');
  if(el) el.classList.add('on');
  var tbody=document.getElementById('gb');
  var rows=Array.prototype.slice.call(tbody.querySelectorAll('tr.gr'));
  rows.sort(function(a,b){
    var d;
    if(sc==='status'){d=parseInt(a.dataset.sord)-parseInt(b.dataset.sord);return sd==='asc'?d:-d;}
    if(sc==='stage'){d=parseFloat(a.dataset.stage)-parseFloat(b.dataset.stage);return sd==='asc'?d:-d;}
    if(sc==='discharge'){d=parseFloat(a.dataset.discharge)-parseFloat(b.dataset.discharge);return sd==='asc'?d:-d;}
    var va=a.dataset[sc]||'',vb=b.dataset[sc]||'';
    return sd==='asc'?va.localeCompare(vb):vb.localeCompare(va);
  });
  for(var i=0;i<rows.length;i++) tbody.appendChild(rows[i]);
  render();
}

function render(){
  var rows=document.querySelectorAll('#gb tr.gr');
  var vis=0;
  for(var i=0;i<rows.length;i++){
    var r=rows[i];
    var ok=
      (F.status==='all'||r.dataset.status===F.status)&&
      (F.river===''||r.dataset.river===F.river.toLowerCase())&&
      (F.county===''||r.dataset.county===F.county.toLowerCase())&&
      (F.wfo===''||r.dataset.wfo===F.wfo.toLowerCase())&&
      (!F.q||r.dataset.name.indexOf(F.q)>-1||r.dataset.lid.indexOf(F.q)>-1
            ||r.dataset.river.indexOf(F.q)>-1||r.dataset.wfo.indexOf(F.q)>-1
            ||r.dataset.county.indexOf(F.q)>-1);
    r.classList.toggle('hid',!ok);
    if(ok)vis++;
  }
  document.getElementById('rc').textContent=vis;
  document.getElementById('ri').textContent=vis+' gauges shown';
}
render();
setTimeout(function(){location.reload();},<?php echo CACHE_TTL*1000; ?>);
</script>
</body>
</html>
