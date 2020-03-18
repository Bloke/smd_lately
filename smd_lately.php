<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_lately';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.4.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Show recently viewed/popular articles';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

/** Uncomment me, if you need a textpack
$plugin['textpack'] = <<<EOT
#@admin
#@language en-gb
abc_sample_string => Sample String
abc_one_more => One more
#@language de-de
abc_sample_string => Beispieltext
abc_one_more => Noch einer
EOT;
**/
// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
if (class_exists('\Textpattern\Tag\Registry')) {
    Txp::get('\Textpattern\Tag\Registry')
        ->register('smd_lately');
}

/**
 * smd_lately
 *
 * A Textpattern CMS plugin for displaying recent visitor stats, or page hits.
 *
 * @author Stef Dawson
 * @link   https://stefdawson.com/
 */
function smd_lately ($atts, $thing = null)
{
    global $prefs, $thisarticle, $permlink_mode;

    // Check logging is on.
    if ( $prefs['logging'] != 'all') {
        trigger_error(smd_rv_gTxt('logging_enabled'), E_USER_NOTICE);
        return;
    }

    extract(lAtts(array(
        'by'           => 'SMD_CURRENT', // Default is the IP address of the current visitor. Can be empty for 'all' visitors
        'section'      => '',
        'status'       => '',
        'time'         => 'past',
        'include'      => '',
        'exclude'      => '',
        'within'       => '',
        'from'         => '',
        'to'           => '',
        'show_current' => 0,
        'form'         => '',
        'limit'        => 10,
        'sort'         => 'time desc',
        'wraptag'      => '',
        'break'        => 'br',
        'class'        => __FUNCTION__,
        'active_class' => 'active',
        'label'        => '',
        'labeltag'     => '',
        'delim'        => ',',
        'param_delim'  => ':',
        'silent'       => 0,
        'cache_time'   => '0', // in seconds
        'hashsize'     => '6:5',
        'debug'        => 0,
    ), $atts));

    // Make a unique hash value for this instance so the results can be cached in a file.
    $uniq = '';
    $md5 = md5($by.$sort.$section.$within.$from.$to.$include.$exclude);

    list($hashLen, $hashSkip) = explode(':', $hashsize);

    for ($idx = 0, $cnt = 0; $cnt < $hashLen; $cnt++, $idx = (($idx+$hashSkip) % strlen($md5))) {
        $uniq .= $md5[$idx];
    }

    $var_lastmod = 'smd_lately_lmod_'.$uniq;
    $var_data = find_temp_dir().DS.'smd_lately_data_'.$uniq;
    $lastmod = get_pref($var_lastmod, 0);
    $read_cache = (($cache_time > 0) && ((time() - $lastmod) < $cache_time)) ? true : false;

    // Sanitize sort options.
    $sortBits = do_list($sort, " ");
    $sortBits[0] = (empty($sortBits[0])) ? 'time' : $sortBits[0];

    if (!isset($sortBits[1]) || !in_array($sortBits[1], array('desc', 'asc'))) {
        $sortBits[1] = 'desc';
        $sort = join(" ", $sortBits);
    }

    // Make a note of the current article if appropriate.
    if ($permlink_mode=='messy') {
        $match_col = 'ID';
        $match_tacol = 'thisid';
        $match_prefix = 'id=';
    } else {
        $match_col = $match_tacol = 'url_title';
        $match_prefix = '';
    }

    $curr_art = ($thisarticle) ? $thisarticle[$match_tacol] : '';

    if ($read_cache) {
        $rs = unserialize(file_get_contents($var_data));
    } else {
        // IP address clause
        $ip = '';

        if ($by == 'SMD_CURRENT') {
            if (function_exists('remote_addr')) {
                $ip = remote_addr();
            } else {
                $ip = serverSet('REMOTE_ADDR');

                if (($ip == '127.0.0.1' || $ip == serverSet('SERVER_ADDR')) && serverSet('HTTP_X_FORWARDED_FOR')) {
                    $ips = explode(', ', serverSet('HTTP_X_FORWARDED_FOR'));
                    $ip = $ips[0];
                }
            }

            if ($ip) {
                $ip = " AND ip='".doSlash($ip)."'";
            }
        } elseif ($by == 'SMD_ALL') {
            $by = '';
        }

        // Make sure we don't include the current article
        // Note the regexp is anchored to the end with a $.
        $thisicle = '';

        if (isset($thisarticle)) {
            if (!$show_current) {
                $urltitle = $thisarticle[$match_tacol];
                $thisicle = ($urltitle) ? " AND page NOT LIKE '%$match_prefix$urltitle'" : '';
            }
        }

        // Filter out article_list pages and other (un)desirables.
        $rules = array(
            "page NOT LIKE '/'", // Goodbye front page
            "page NOT LIKE ''",
            "page NOT LIKE '%q=%'", // Goodbye searches
            "page NOT LIKE '%c=%'", // Goodbye cat lists
            "page NOT LIKE '%pg=%'", // Goodbye multi-page lists
            "page NOT LIKE '%category=%'",
            "page NOT LIKE '/category/%'",
            "page NOT LIKE '%author=%'",
            "page NOT LIKE '/author/%'",
        );

        if ($section) {
            $section = do_list($section, $delim);
            $subrule = array();

            foreach ($section as $sec) {
                if ($permlink_mode == 'messy') {
                    $subrule[] = "page LIKE '%s=$sec%'";
                } else {
                    $subrule[] = "page LIKE '/$sec%' AND page NOT REGEXP '^/$sec/?$'";
                }
            }

            $subrule = '(' . join(' OR ', $subrule) . ')';
            $rules[] = $subrule;
        } else {
            // Exclude any rows that just contain the section (i.e. article list pages).
            $allSecs = safe_column('name', 'txp_section', "1=1", $debug);
            $rules[] = "page NOT REGEXP '^/(" . join("|", $allSecs) . ")/?$'";
        }

        // Process any manual includes.
        if ($include) {
            $include = do_list($include, $delim);
            $subrules = array();

            foreach ($include as $inc) {
                $regex = $like = false;
                $column = 'ip';
                $parts = do_list($inc, $param_delim);
                $match = array_pop($parts);

                foreach ($parts as $part) {
                    switch ($part) {
                        case "ip":
                        case "host":
                        case "page":
                        case "refer":
                        case "method":
                            $column = $part;
                            break;
                        case "like":
                            $like = true;
                            break;
                        case "regex":
                            $regex = true;
                            break;
                    }
                }

                $subrules[] = $regex ? "$column REGEXP '".doSlash(preg_quote($match))."'" : (($like) ? "$column LIKE '%".doSlash($match)."%'" : "$column = '".doSlash($match)."'");
            }

            $rules[] = '('.join(' OR ', $subrules).')';
        }

        // Process any manual excludes.
        if ($exclude) {
            $exclude = do_list($exclude, $delim);

            foreach ($exclude as $exc) {
                $regex = $like = false;
                $column = 'ip';
                $parts = do_list($exc, $param_delim);
                $match = array_pop($parts);

                foreach ($parts as $part) {
                    switch ($part) {
                        case "ip":
                        case "host":
                        case "page":
                        case "refer":
                        case "method":
                            $column = $part;
                            break;
                        case "like":
                            $like = true;
                            break;
                        case "regex":
                            $regex = true;
                            break;
                    }
                }

                $rules[] = $regex ? "$column NOT REGEXP '".doSlash(preg_quote($match))."'" : (($like) ? "$column NOT LIKE '%".doSlash($match)."%'" : "$column != '".doSlash($match)."'");
            }
        }

        // Filter by time frame.
        $fromstamp = $tostamp = $diffstamp = 0;
        $thismonth = date('F', mktime(0,0,0,strftime('%m'),1));
        $thisyear = strftime('%Y');

        if ($from) {
            $from = str_replace("?month", $thismonth, $from);
            $from = str_replace("?year", $thisyear, $from);
            $fromstamp = strtotime($from);

            if ($fromstamp) {
                $rules[] = "UNIX_TIMESTAMP(time) > $fromstamp";
            } else {
                if (!$silent) {
                    trigger_error(smd_rv_gTxt('invalid_ts', array("{where}" => 'from')), E_USER_NOTICE);
                }
            }
        }

        if ($to) {
            $to = str_replace("?month", $thismonth, $to);
            $to = str_replace("?year", $thisyear, $to);
            $tostamp = strtotime($to);

            if ($tostamp) {
                $rules[] = "UNIX_TIMESTAMP(time) < $tostamp";
            } else {
                if (!$silent) {
                    trigger_error(smd_rv_gTxt('invalid_ts', array("{where}" => 'to')), E_USER_NOTICE);
                }
            }
        }

        if ($within) {
            if ($tostamp) {
                $refstamp = $tostamp;
                $refdir = '-';
            } elseif ($fromstamp) {
                $refstamp = $fromstamp;
                $refdir = '+';
            } else {
                $refstamp = time();
                $refdir = '-';
            }

            $diffstamp = strtotime($refdir.$within, $refstamp);

            if ($diffstamp) {
                $rules[] = "UNIX_TIMESTAMP(time) ".(($refdir == '-') ? '> ' : '< ' ).$diffstamp;
            } else {
                if (!$silent) {
                    trigger_error(smd_rv_gTxt('invalid_ts', array("{where}" => 'within')), E_USER_NOTICE);
                }
            }
        }

        if ($debug) {
            echo "++ smd_lately RULES ++";
            dmp($rules);

            if ($from || $to || $within) {
                echo "++ TIME WINDOW ++";
                if ($from) {
                    dmp("FROM: " . date('Y-M-d H:i:s', $fromstamp));
                }

                if ($within) {
                    dmp("WITHIN: " . date('Y-M-d H:i:s', $diffstamp), $within . (($refdir == '-') ? " BEFORE TO DATE" : " AFTER FROM DATE"));
                }

                if ($to) {
                    dmp("TO: ". date('Y-M-d H:i:s', $tostamp));
                } else {
                    dmp("TO: ". date('Y-M-d H:i:s', time()));
                }
            }
        }

        $rules = ' AND ' . join(' AND ', $rules);

        $query = 'SELECT count(page) as popularity, page, MAX(time) as time FROM '.PFX.'txp_log WHERE 1=1'.$ip.$thisicle.$rules.' AND status = 200 GROUP BY page ORDER BY '.$sort;
        $rs = getRows($query, $debug);

        // Store the current document in the cache and datestamp it.
        if ($cache_time > 0) {
            if ($debug > 1) {
                dmp('++ DATA CACHED to '.$var_data.' ++');
            }

            set_pref($var_lastmod, time(), 'smd_lately', PREF_HIDDEN, 'text_input');
            $fh = fopen($var_data, 'wb');
            fwrite($fh, serialize($rs));
            fclose($fh);
        }
    }

    if ($debug > 1) {
        dmp($rs);
    }

    // Set up counters and create query params.
    $count = 0;
    $out = array();

    if ($status) {
        $status = do_list($status, $delim);
        $stati = array();

        foreach ($status as $stat) {
            if (empty($stat)) {
                continue;
            } elseif (is_numeric($stat)) {
                $stati[] = $stat;
            } else {
                $stati[] = getStatusNum($stat);
            }
        }

        $status = " AND Status IN (".join(',', $stati).")";
    }

    switch ($time) {
        case "":
        case "any":
            $time = "";
            break;
        case "future":
            $time = " AND Posted > NOW()";
            break;
        default:
            $time = " AND Posted < NOW()";
            break;
    }

    // Process the result set.
    $articles = array();

    if ($rs) {
        // Loop until limit reached.
        foreach ($rs as $row) {
            if ($limit > 0 && is_numeric($limit) && $count == $limit) {
                break;
            }

            if ($permlink_mode == 'messy') {
                preg_match('@id=(\d+)@', $row['page'], $matches);

                // Add 'id' to trick array_multisort into believing it's an associative array.
                $da_url = isset($matches[1]) ? 'id'.$matches[1] :  '';
            } else {
                // Strip off any query params.
                $justurl = explode('?',$row['page']);
                $urlpart = explode('/', rtrim($justurl[0],"/"));
                $da_url = $urlpart[count($urlpart)-1];
            }

            if ($da_url) {
                if (isset($articles[$da_url])) {
                    $articles[$da_url][0] += $row['popularity'];
                    if ($row['time'] > $articles[$da_url][1]) {
                        $articles[$da_url][1] = $row['time'];
                    }
                } else {
                    $articles[$da_url] = array(
                        $row['popularity'],
                        $row['time'],
                    );

                    $count++;
                }
            }
        }

        if ($debug > 1) {
            echo '++ ORIGINAL LOG LIST ++';
            dmp($articles);
        }

        if ($articles) {
            // If sorting by popularity, re-order the results in case they've been subsequently aggregated.

            if ($sortBits[0] == 'popularity') {
                foreach ($articles as $key => $row) {
                    $apop[$key]  = $row[0];
                    $atime[$key] = $row[1];
                }

                $dir = ($sortBits[1] == 'asc') ? SORT_ASC : SORT_DESC;
                array_multisort($apop, $dir, $atime, $dir, $articles);
            }

            if ($permlink_mode == 'messy') {
                // Strip off the fake 'id' identifiers in the array keys.
                foreach ($articles as $key => $row) {
                    $tmp[substr($key, 2)] = $row;
                }

                $articles = $tmp;
            }

            if ($debug > 2) {
                echo '++ FINAL LOG LIST++';
                dmp($articles);
            }

            $darticles = safe_rows('*, unix_timestamp(Posted) as uPosted, unix_timestamp(Expires) as uExpires, unix_timestamp(LastMod) as uLastMod', 'textpattern', "$match_col IN ('" . join("','", array_keys($articles)) . "')".$status.$time, $debug);

            // Refactor the article list keyed on url_title or ID (depending on permlink_mode).
            $urlicles = array();

            foreach ($darticles as $darticle) {
                $urlicles[$darticle[$match_col]] = $darticle;
            }

            // Iterate over the _original_ article list (from the first query)
            // and pluck out the relevant article contents.
            foreach ($articles as $urlTitle => $darticle) {
                if (isset($urlicles[$urlTitle])) {
                    $theTime = strtotime($darticle[1]);
                    $aktiv = $thisarticle && $show_current && $urlTitle == $curr_art;
                    $replacements = array(
                        "{smd_lately_active}"         => (($aktiv) ? ' class="'.$active_class.'"' : ''),
                        "{smd_lately_activeclass}"    => (($aktiv) ? $active_class : ''),
                        "{smd_lately_count}"          => $darticle[0],
                        "{smd_lately_fulldate}"       => $darticle[1],
                        "{smd_lately_date}"           => strftime("%F", $theTime),
                        "{smd_lately_date_year}"      => strftime("%Y", $theTime),
                        "{smd_lately_date_month}"     => strftime("%m", $theTime),
                        "{smd_lately_date_monthname}" => strftime("%B", $theTime),
                        "{smd_lately_date_day}"       => strftime("%d", $theTime),
                        "{smd_lately_date_dayname}"   => strftime("%A", $theTime),
                        "{smd_lately_time}"           => strftime("%T", $theTime),
                        "{smd_lately_time_hour}"      => strftime("%H", $theTime),
                        "{smd_lately_time_minute}"    => strftime("%M", $theTime),
                        "{smd_lately_time_second}"    => strftime("%S", $theTime),
                    );

                    article_push();
                    populateArticleData($urlicles[$urlTitle]);
                    $out[] = ($thing) ? parse(strtr($thing, $replacements)) : (($form) ? parse_form(strtr($form, $replacements)) : href( $urlicles[$urlTitle]['Title'], permlinkurl($urlicles[$urlTitle]), $replacements['{smd_lately_active}'] ));
                    article_pop();
                }
            }
        }
    }

    return ($out) ? doLabel($label, $labeltag).doWrap($out, $wraptag, $break, $class) : '';
}

// ------------------------
// Plugin-specific replacement strings - localise as required.
function smd_rv_gTxt($what, $atts = array())
{
    $lang = array(
        'invalid_ts' => 'Invalid date/time info in "{where}" attribute.',
        'logging_enabled' => 'Logging must be set to "All hits" in Basic Pefs.',
    );

    return strtr($lang[$what], $atts);
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
notextile. <div id="smd_help">

h1. smd_lately

List the most recent articles viewed by the current visitor or, alternatively, all visitors. Can also order by popularity (most viewed).

h2(#features). Features

* List recently viewed articles: useful on product pages or in a shopping cart
* Show a list of the most popular articles
* Limit either list by section or arbitrary value (ip, host, page, referer) if you wish
* Uses the visitor logs
* Automatically filters out article list pages, category, author or search visits

h2(#author). Author / credits

"Stef Dawson":https://stefdawson.com/contact.

h2(#install). Installation / Uninstallation

p(required). Requires Logging to be set to _All hits_ (will issue a warning if not)

Download the plugin from either "textpattern.org":http://textpattern.org/plugins/1110/smd_lately, or the "software page":https://stefdawson.com/sw, paste the code into the TXP Admin -> Plugins pane, install and enable the plugin. Visit the "forum thread":https://forum.textpattern.com/viewtopic.php?id=31341 for more info or to report on the success or otherwise of the plugin.

To uninstall, simply delete from the Admin -> Plugins page.

h2(#np). @<txp:smd_lately>@

Place this tag anywhere you wish to display a list of recently viewed articles. The tag may be used as a single tag (with an optional form) or as a container. Inside the form/container you can use any standard article tags -- @<txp:title />@, @<txp:excerpt />@, @<txp:posted />@, etc -- to format the list however you like. Without either a form or a container, the plugin displays a hyperlinked title to the article.

The following attributes can be used to modify the plugin's output:

h3(atts). Attributes

h4. Log filtering and sort order attributes

* %(atnm)by% : use @by=""@ or @by="SMD_ALL"@ to view the most recent articles by all site visitors. By default (i.e. if the attribute is omitted) the plugin only shows the recent articles viewed by the current visitor
* %(atnm)section% : choose to show articles only from this list of sections. Default: unset (i.e. from all sections). %(required)Note that you must be using either messy mode or a permlink mode with @/section@ in it to filter by section%
* %(atnm)show_current% : Display the article being viewed in the list: 1=Yes; 0=No. Ignored if in an article_list page. Default: 0
* %(atnm)include% : ensure that log file entries that match this set of criteria are included in the list. This attribute takes up to 3 parameters, separated by @param_delim@ (default is the colon).
** First is the name of a field to match against. Choose from @ip@ (the default), @host@, @page@ or @refer@
** Second is an optional parameter to indicate whether the match is a regular expression, wild or an exact match. Use @regex@ if you wish this match to be considered as a regular expression, specify @like@ if you want to check if the text is a simple 'wild' match (it's similar to, but quicker than, a regex), or omit the parameter entirely if you want an 'exact match' to be considered
** Finally, specify the text you want to match with that field. For example, @include="host:like:www.domain.com"@ would only include results that had @www.domain.com@ somewhere in their host name. Using @include="192.168.2.200"@ would show pages from any internal meddling you may have done on your local XAMPP server. Note that if you omit parameters 1 and 2, the plugin uses the defaults
* %(atnm)exclude% : ensure that log file entries that match this set of criteria are *not* included in the list. Use this in the same manner as @include@
* %(atnm)from% : date / time stamp (written in English) of the _earliest_ date to consider in the logs. You may specify @?month@ and/or @?year@ to have the plugin replace it with the current month/year. Default: unset
* %(atnm)to% : date / time stamp (written in English) of the _most recent_ date to consider in the logs. You may specify @?month@ and/or @?year@ to have the plugin replace it with the current month/year. Default: current time
* %(atnm)within% : date offset (written in English) which specifies a time window in which you are interested. For example @within="36 hours"@ would show results from the last 36 hours. If you specify a @to@ date, the value of @within@ is subtracted from it. If you specify a @from@ date, the value of @within@ is _added_ to it. If you specify both @from@ and @to@, the offset is calculated relative to the @to@ attribute. Default: unset
* %(atnm)sort% : order the list by either @time@, or @popularity@. Add @asc@ or @desc@ to choose ascending or descending sort order. Default: @time desc@
* %(atnm)limit% : show this many items in the list. 0 = unlimited. Default: 10

h4. Article filter attributes

* %(atnm)status% : Only articles with one of these listed status values are displayed in the list. Default: 4 (@live@)
* %(atnm)time% : If an article in the log has its posted timestamp in the time period indicated in this attribute, it will be displayed. Choose from @any@, @future@ or @past@ (the default). Leaving this at its default prevents any future-dated articles that you may be previewing from showing up in the list

h4. Display attributes

* %(atnm)wraptag% : the (X)HTML tag, without its brackets, to wrap the list with. Default: unset
* %(atnm)class% : the CSS class name to apply to the wraptag. Default: @smd_lately@
* %(atnm)active_class% : the CSS class name to set as the active class when @show_current="1"@ is used. Default: @active@. See "replacement variables":#reps for details on how to actually insert this into your markup
* %(atnm)break% : the (X)HTML tag, without its brackets, to wrap each item with. Default: @br@
* %(atnm)label% : the label text to add to the top of the list. Default: unset
* %(atnm)labeltag% : the (X)HTML tag, without its brackets, to wrap the label with. Default: unset

h4. Plugin configuration attributes

* %(atnm)form% : if you prefer to use a form instead of the container to hold your markup and tags, specify it here. Default: unset
* %(atnm)delim% : the delimiter to use between items in attribute lists (@section@, @include@, @exclude@, @status@). Default: comma
* %(atnm)param_delim% : the delimiter to use between parameters inside an attribute (@include@, @exclude@). Default: colon
* %(atnm)cache_time% : if set, the results are cached in a temporary file for the designated number of seconds. Subsequent calls to smd_lately (e.g. refreshing the page) will read the cached information instead of trawling the logs, thus cutting down on server load. After @cache_time@ seconds have elapsed, the next page refresh will cause the information to be recalculated. Note that the file name of the cached data is of the form @smd_lately_data_ABCDEF@ where ABCDEF is a unique string that applies to this particular smd_lately tag. If you alter the attributes there is a good chance it will create a new temporary file and the plugin does not clean up after itself. For this reason it's probably a good idea to play with the plugin and set your attributes up before setting @cache_time@

h3(atts#reps). Replacement variables

In addition to regular TXP article tags, you may employ any of the following codes in your form/container to display the corresponding value:

* %(atnm){smd_lately_activeclass}% : the raw classname you specified in your @active_class@ attribute: only set if current article matches
* %(atnm){smd_lately_active}% : a full @ class="class_name"@ string: only set if current article matches
* %(atnm){smd_lately_count}% : the number of times the article has been accessed
* %(atnm){smd_lately_fulldate}% : the article's last access date stamp
* %(atnm){smd_lately_date}% : the article's last access date
* %(atnm){smd_lately_date_year}% : the article's last access year
* %(atnm){smd_lately_date_month}% : the article's last access month (number)
* %(atnm){smd_lately_date_monthname}% : the article's last access month (full name)
* %(atnm){smd_lately_date_day}% : the article's last access day (number)
* %(atnm){smd_lately_date_dayname}% : the article's last access day (full name)
* %(atnm){smd_lately_time}% : the article's last access time stamp
* %(atnm){smd_lately_time_hour}% : the article's last access hour
* %(atnm){smd_lately_time_minute}% : the article's last access minute
* %(atnm){smd_lately_time_second}% : the article's last access second

h3(#caveats). Caveats

Since the plugin uses the TXP logs you need to make sure they are being used (Admin->Basic Prefs). It also means that any visitors using an anonymising proxy will get spurious (or no) results. Their loss.

h2(examples). Examples

h3(#eg1). Example 1: recent article list for current visitor

bc(block). <txp:smd_lately />

h3(#eg2). Example 2: recent articles for all visitors

bc(block). <txp:smd_lately by="" section="archive, about" />

Only shows the articles viewed from the 'archive' and 'about' sections.

h3(#eg3). Example 3: most popular articles across the site

bc(block). <txp:smd_lately by="" sort="popularity"  />

Without using the @by@ attribute, this would give the most popular articles for the current visitor -- probably not what you want!

h3(#eg4). Example 4: tag as a container

bc(block). <txp:smd_lately by=""
     wraptag="ul" break="li" limit="6">
   <txp:permlink><txp:title /></txp:permlink> [{smd_lately_count}]<br />
   <txp:posted /> by <txp:author />
</txp:smd_lately>

Shows the 6 most recent articles accessed by any site visitor. The unordered list output contains a permlinked title, the date the article was posted and the article's author.

h3(#eg5). Example 5: filtering by host

bc(block). <txp:smd_lately by=""
     wraptag="ul" break="li" limit="6"
     exclude="host:id3456-bt.custref.com, refer:">
   <txp:permlink><txp:title /></txp:permlink> [{smd_lately_count}]<br />
</txp:smd_lately>

Shows only records that have some referer information (i.e. we are excluding entries where refer = empty) and those that have NOT been accessed via the unique hostname given to you by your ISP -- assuming @id3456-bt.custref.com@ shows up in the 'host' column whenever you access a TXP article.

If you adjusted the exclude attribute to read @exclude="host:like:bt.custref"@ then any log entries with @bt.custref@ in them would be excluded from consideration.

h3(#eg6). Example 6: filtering by time range

bc(block). <txp:smd_lately by=""
     wraptag="ul" break="li"
     from="?year-?month-01">
   <txp:permlink><txp:title /></txp:permlink> [{smd_lately_count}]<br />
</txp:smd_lately>

Shows only the hits generated from the first day of the current month until now. The recent list will thus 'reset' on the 1st of every month.

If you removed the @from@ attribute and used @within="30 days"@ you would instead see a 'rolling' total of the hits in the previous 30 days (you could also use @within="1 month"@ to get roughly the same result).

h3(#changelog). Changelog

* 23 Jul 09 | 0.10 | Initial release
* 24 Jul 09 | 0.11 | Made backwards compatible with TXP 4.0.8
* 10 Aug 09 | 0.12 | Never assume what remains in the filtered URL is actually an article
* 11 Sep 09 | 0.13 | Added replacement vars
* 19 Feb 10 | 0.20 | Added @status@, @time@, @include@, @exclude@, @delim@, and @param_delim@ ; article list now filtered by @time="past"@ and @status="4"@ by default
* 10 May 10 | 0.21 | Added @from@, @to@ and @within@ (thanks the_ghost) ; added @like@ matching ; removed some power-hungry REGEXP operators in favour of LIKE to improve performance
* 28 May 10 | 0.22 | Fixed default @class@ attribute and removed trailing slash in log pages (thanks maniqui)
* 10 Sep 10 | 0.30 | Added @cache_time@ (thanks pieman) ; now only uses two queries regardless of number of results ; improved messy URL support ; fixed time display problems using @NOW()@ and added @active_class@ (both thanks jelle)
* 13 Mar 14 | 0.31 | Added filtering by method (thanks kees-b)

notextile. </div>
# --- END PLUGIN HELP ---
-->
<?php
}
?>