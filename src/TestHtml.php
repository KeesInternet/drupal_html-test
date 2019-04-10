<?php
namespace Drupal\html_checker;

use DOMDocument;

/**
 * Test the HTML sourc of the current page
 *
 * PHP version 7
 *
 * Test the HTML source of the current page on W3C validation, accessability, Structured data (if present), custom checks, and pagespeed.
 *
 * @category HTML
 * @package  TestHtml
 * @author   Zef Oudendorp <zef@kees-tm.nl>
 * @license  MIT
 * @link     https://packagist.org/packages/keestm/html-test
 */
class TestHtml
{
    protected $accessibility_checker_webservice_id = "";
    protected $accessibility_guides = "WCAG2-AA";
    /*
     * Test the HTML sourc of the current page
     *
     * @return void
     */
    public function __construct($accessibility_checker_webservice_id = "", $accessibility_guides = "")
    {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        if (isset($_GET['test_html'])) {
            if (!empty($accessibility_checker_webservice_id)) {
                $this->accessibility_checker_webservice_id = $accessibility_checker_webservice_id;
            }
            if (!empty($accessibility_guides)) {
                $this->accessibility_guides = implode(",", $accessibility_guides);
            }
            // Override accessibility_guides with get value if present
            if (isset($_GET["accessibility_guides"]) && !empty($_GET["accessibility_guides"])) {
                $this->accessibility_guides = $_GET["accessibility_guides"];
            }
            //no trailing slash!
            $domain = "http".( "on" == $_SERVER["HTTPS"] ? "s" : "" )."://".$_SERVER["SERVER_NAME"];
            //Use URL per template specific testing!
            $test_uri = explode("?", $_SERVER["REQUEST_URI"])[0];
            $table_headers = "<th>".$domain.$test_uri."</th>";
            $table_data = "<td>";
            $table_data.= "<h2>W3C validation</h2>".$this->_validateHtml($domain.$test_uri);
            $table_data.= $this->_accessabilityChecker($domain.$test_uri);
            $html_source = "";//this variable will be filled after the get_dom_obj function!
            $dom_obj = $this->_getDomObj($domain, $test_uri, $html_source);
            //Test rich snippets if present
            $table_data.= $this->_testStructuredData($dom_obj);
            if (!$dom_obj) {
                $table_data.= "<li>Header did NOT return 200!</li>";
            } else {
                $table_data.= $this->_checkSource($dom_obj, $domain, $html_source);
            }
            $table_data.= $this->_testPagespeed($domain.$test_uri);
            $table_data.= "</td>";
            $table = "<table cellpadding='10' border='1'><tr>".$table_headers."</tr><tr>".$table_data."</tr></table>";
            echo $table;
            die();
        }
    }

    /**
     * Valide HTML using W3C validation API
     *
     * @param string $test_uri of the page we're on
     *
     * @return string HTML feedback list
     *
     * Does not work when cookiewall is present!
     */
    private function _validateHtml($test_uri)
    {
        $messages = "<ul>";
        $result = $this->_curl('https://validator.w3.org/nu/?out=json&doc='.$test_uri);
        $results = json_decode($result, true);
        foreach ($results["messages"] as $key => $message) {
            //only show errors if an actual message is present!
            $messages.= (!empty($message["message"])? "<li style='color: red'>".$message["message"].(!empty($message['extract'])? ": <i>".htmlspecialchars($message['extract']) : "")."</i></li>" : "");
        }
        $messages.= "</ul>";
        return $messages;
    }

    /**
     * Valide HTML using W3C validation API
     *
     * @param string  $url we're about to cURL
     * @param string  $data_string data to post
     * @param boolean $get_http_status to only return the HTTP status of the url
     *
     * @return mixed
     *
     * Used by:
     * this->_validateHtml()
     * this->_getDomObj()
     * this->_testPagespeed()
     * this->_accessabilityChecker()
     * this->_testStructuredData()
     * this->_isDeadLink()
     */
    private function _curl($url, $data_string = "", $get_http_status = "")
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, ($get_http_status? true:false)); //still returning header?
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        //Disable SSL verification!
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        //post fields if present
        if (!empty($data_string)) {
            $http_header = array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string)
            );
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $http_header);
        }
        if ($get_http_status) {
            curl_setopt($curl, CURLOPT_NOBODY, true);
        }
        //Expressionengine uses these options
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_VERBOSE, true);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.52 Safari/537.17');
        $return_data = curl_exec($curl);
        // Check for errors and display the error message
        if ($errno = curl_errno($curl)) {
            $error_message = curl_strerror($errno);
            echo "cURL error ({$errno}):\n {$error_message} for ".$url."<br />";
        }
        if ($get_http_status) {
            $return_data = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        }
        curl_close($curl);
        return $return_data;
    }

    /**
     * Check accessability of the HTML  source
     * Does not work when a cookiewall is present!
     *
     * @param string $uri of the page we're on
     *
     * @return string HTML feedback list
     */
    private function _accessabilityChecker($uri)
    {
        if (!empty($this->accessibility_checker_webservice_id)) {
            $result = $this->_curl("https://achecker.ca/checkacc.php?uri=".$uri."&id=".$this->accessibility_checker_webservice_id."&output=rest&guide=".$this->accessibility_guides);
            $xml = simplexml_load_string($result, "SimpleXMLElement", LIBXML_NOCDATA);
            $json = json_encode($xml);
            $results = json_decode($json, true);
            $status = $results["summary"]["status"];
            $guideline = (is_array($results["summary"]["guidelines"]["guideline"])? implode(", ", $results["summary"]["guidelines"]["guideline"]) : $results["summary"]["guidelines"]["guideline"]);
            $message = "<h2>Accessability (guideline ". $guideline .") status: <span style='color:".("FAIL" == $status? "red":"green")."'>".$status."</span></h2>";
            $messages = "";
            if (isset($results["results"]["result"])) {
                if (isset($results["results"]["result"][0])) {
                    foreach ($results["results"]["result"] as $result) {
                        if ("Error" == $result["resultType"]) {
                            $messages.= "<li style='color:red'>".$result["errorMsg"].": <i>".htmlspecialchars($result["errorSourceCode"])."</i></li>";
                        }
                    }
                } else {
                    if ("Error" == $results["results"]["result"]["resultType"]) {
                        $messages = "<li style='color:red'>".$results["results"]["result"]["errorMsg"].": <i>".htmlspecialchars($results["results"]["result"]["errorSourceCode"])."</i></li>";
                    }
                }
            }
            if ($messages) {
                $message.= "<ul>".$messages."</ul>";
            }
            return $message;
        }
    }

    /**
     * Get DOM object & HTML source!
     *
     * @param string $domain of the site we'r e on
     * @param string $test_uri of the page we're on
     * @param string $html_source of the page we're on, will be given a value inside this function!
     *
     * @return object domObject of the page we're on
     */
    private function _getDomObj($domain, $test_uri, &$html_source)
    {
        //Is this URL available?
        if ($this->_isDeadLink($domain, $test_uri)) {
            return false;
        } else {
            $html_source = $this->_curl($domain.$test_uri, false);
            libxml_use_internal_errors(true);
            $dom_obj = new DOMDocument();
            $dom_obj->loadHTML($html_source);
            return $dom_obj;
        }
    }

    /**
     * Check if the link returns header 200, 301 or 302
     *
     * @param string $domain of the site we're on
     * @param string $url of the link which we're about to check
     *
     * @return boolean
     *
     * Used by:
     * this->__construct()
     * this->_checkLinkTags()
     * this->_checkScriptTags()
     * this->_checkImgTags()
     * this->checkDetectifyMeasures()
     */
    private function _isDeadLink($domain, $url)
    {
        //Check if it's a  local or remote (or even protocol relative!) URL so we can see if the content actually exists
        $url = (strstr($url, "http")? "": (preg_match("#^\/\/#", $url)? "https:": $domain)).$url;
        $http_status = $this->_curl($url, false, true);
        if (!in_array($http_status, ["200", "301", "302"])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check the source with custom checks
     *
     * @param object $dom_obj of the entire HTML source
     * @param string $domain of the site we're on
     * @param string $html_source to find stray tags
     *
     * @return string HTML feedback list
     */
    private function _checkSource($dom_obj, $domain, $html_source)
    {
        //Check semantic structure
        $messages = $this->_checkStructureHeadings($dom_obj);
        $messages.= "<h2>Custom checks</h2>";
        $messages.= "<ul>";
        $messages.= $this->_checkSingleH1($dom_obj);
        //Check for active states in main- & sub-nav!
        $messages.= $this->_checkNavActiveStates($dom_obj);
        //Check favicon & css tags
        $messages.= $this->_checkLinkTags($domain, $dom_obj);
        //Check description- msapplications- & OG-tags
        $messages.= $this->_checkMetaTags($dom_obj);
        //Check javascript tags
        $messages.= $this->_checkScriptTags($domain, $dom_obj);
        //Check images (src & alt)
        $messages.= $this->_checkImgTags($domain, $dom_obj);
        //Check forms for validation classes (jQuery validate)
        $messages.= $this->_checkFormValidation($dom_obj);
        //Check Detectify measures
        $messages.= $this->_checkDetectifyMeasures($domain, $dom_obj);
        //Find stray (non inline javascript) twig/EE tags
        $messages.= $this->_findStrayTags($html_source);
        $messages.= "</ul>";
        return $messages;
    }

    /**
     * Make the semantic structure of the HTML layout visible
     *
     * @param object $dom_obj of the entire HTML source of the page we're on
     *
     * @return string HTML indented list representing the semantic structure of the page we're on
     */
    private function _checkStructureHeadings($dom_obj)
    {
        //HTML5 elements which require headers
        $heading_requiring_elements = array("header", "section", "article", "nav", "aside", "footer");
        $structure = $this->_findStructure($dom_obj, $heading_requiring_elements);
        return "<h2>Semantic structure</h2><ul>".$this->_showStructure($structure)."</ul>";
    }

    /**
     * Recursively check the source for semantic structure
     *
     * @param object $dom_obj of the entire HTML source of the page we're on
     * @param array $heading_requiring_elements HTML5 elements which require headers
     *
     * @return array containing the semantic structure of the page we're on
     */
    private function _findStructure($dom_obj, $heading_requiring_elements)
    {
        $headings = array();
        $sub_structure = array();
        $found_elements = array();
        if ($dom_obj->hasChildNodes()) {
            foreach ($dom_obj->childNodes as $child_node) {
                $found_element = array();
                if (!preg_match("#h\d+#i", $child_node->nodeName)) { //not a header!
                    if (in_array($child_node->nodeName, $heading_requiring_elements)) {//we're looking for this element!
                        $element_found = true;
                        $found_element["element"] = $child_node->nodeName;
                        $found_element["selector"] = ($child_node->getAttribute("id")? "#".$child_node->getAttribute("id"):($child_node->getAttribute("class")? ".".$child_node->getAttribute("class") : ""));
                        //now let's find it's header inside!
                        $element_sub_structure = $this->_findStructure($child_node, $heading_requiring_elements);
                        if (!empty($element_sub_structure)) {
                            $found_element["sub_structure"] = $element_sub_structure;
                        }
                        $found_elements[] = $found_element;
                    } else {//NOT the element we are looking for, but maybe there's one inside?
                        $found_structure = $this->_findStructure($child_node, $heading_requiring_elements);
                        if (!empty($found_structure)) {
                            $sub_structure[] = $found_structure;
                        }
                    }
                } elseif (preg_match("#h\d+#i", $child_node->nodeName)) { //this is a header!
                    $headings[] = array("selector" => $child_node->nodeName, "value" => $child_node->nodeValue);
                }
            }
        }
        $structure = array();
        if (count($headings) > 0) {
            $structure["headings"] = $headings;
        }
        if (count($found_elements) > 0) {
            $structure = array_merge($structure, $found_elements);
        }
        if (count($sub_structure) > 0) {
            if (count($sub_structure) == 1) {
                $sub_structure = $sub_structure[0];
            }
            $structure = array_merge($structure, $sub_structure);
        }
        return $structure;
    }

    /**
     * Recursively transform found semantic structure array to an indented list
     *
     * @param array $structure represents the semantic structure of the HTML layout as returned by_findStructure
     *
     * @return string HTML indented list representing the semantic structure of the page we're on
     */
    private function _showStructure($structure)
    {
        $list = "";
        if (is_array($structure)) {
            foreach ($structure as $key => $node) {
                if (isset($node["element"]) && isset($node["selector"])) {
                    $list.= "<li style='color:green'>";
                    $list.= $node["element"]."<i>".$node["selector"]."</i>";
                    $list.= "<ul>";
                    if (isset($node["sub_structure"]["headings"])) {
                        // $list.= "<li>Single heading:</li>";
                        $list.= $this->_showStructure($node);
                    } elseif (isset($node["sub_structure"][0]["headings"])) {
                        // $list.= "<li>Multiple headings:</li>";
                        $list.= $this->_showStructure($node["sub_structure"]);
                    } elseif (isset($node["sub_structure"][0])) {
                        // $list.= "<li>More substructure:</li>";
                        $list.= $this->_showStructure($node["sub_structure"]);
                    }
                    $list.= "</ul>";
                    $list.= "</li>";
                } elseif ("headings" == $key && isset($node[0])) {
                    foreach ($node as $heading) {
                        if (isset($heading["selector"])) {
                            $list.= "<li style='color:green'>".$heading["selector"]." <i>".($heading["value"]? $heading["value"] : "-empty-")."</i></li>";
                        }
                    }
                    // $list.= "<li>Elements after heading:</li>";
                    $list.= $this->_showStructure($node);
                } else {
                    // $list.= "<li>More elements:</li>";
                    $list.= $this->_showStructure($node);
                }
            }
        }
        return $list;
    }

    /**
     * Check if there's only 1 (required) H1 heading present in the HTML DOM object
     *
     * @param object $dom_obj of the entire HTML source of the page we're on
     *
     * @return string HTML feedback list
     */
    private function _checkSingleH1($dom_obj)
    {
        $messages= "";
        $h1_tags = $dom_obj->getElementsByTagName('h1');
        if (isset($h1_tags->length)) {
            if ($h1_tags->length == 0) {
                return "<li style='color:red'>NO H1 tag found!</li>";
            } elseif ($h1_tags->length == 1) {
                foreach ($h1_tags as $h1_tag) {
                    return "<li style='color:green'>Single H1 <i>".($h1_tag->nodeValue? $h1_tag->nodeValue : "-empty-")."</i> tag found</li>";
                }
            } elseif ($h1_tags->length > 1) {
                $messages.= "<li style='color:red'>".$h1_tags->length." H1 tags found:<ul>";
                foreach ($h1_tags as $h1_tag) {
                    $messages.= "<li><i>".($h1_tag->nodeValue? $h1_tag->nodeValue : "-empty-")."</i></li>";
                }
                $messages.="</ul>There should only be one!</li>";
                return $messages;
            }
        }
    }

    /**
     * Check if there's main- & subnavigation present, and if so, check if they have an active state (CSS class)
     *
     * @param object $dom_obj of the entire HTML source of the page we're on
     *
     * @return string HTML feedback list
     */
    private function _checkNavActiveStates($dom_obj)
    {
        //main nav
        $messages = "";
        $mainnav_message = "";
        foreach ($dom_obj->getElementsByTagName('header') as $header) {
            $mainnav_message = $this->_findActiveState($header);
        }
        if (empty($mainnav_message)) {
            $messages = "<li style='color:red'><strong>NO</strong> main navigation active states found on a or listing tags</li>";
        } else {
            $messages = "<li style='color:green'>Main navigation ".$mainnav_message."</li>";
        }
        //subnav present?
        $subnav_message = "";
        $subnav_found = false;
        //Try to detect the subnav by ul-classname
        foreach ($dom_obj->getElementsByTagName('ul') as $ul) {
            if (strstr($ul->getAttribute("class"), "nav")) {
                $subnav_found = true;
                $subnav_message = $this->_findActiveState($ul);
                if (!empty($subnav_message)) {
                    break;
                }
            }
        }
        //No subnav found yet? Try to detect the subnav by ul id!
        if (!$subnav_found) {
            foreach ($dom_obj->getElementsByTagName('ul') as $ul) {
                if (strstr($ul->getAttribute("id"), "nav")) {
                    $subnav_found = true;
                    $subnav_message = $this->_findActiveState($ul);
                    if (!empty($subnav_message)) {
                        break;
                    }
                }
            }
        }
        if ($subnav_found) {
            if (!empty($subnav_message)) {
                $messages.= "<li style='color:green'>SUB navigation ".$subnav_message."</li>";
            } else {
                $messages.= "<li style='color:red'>SUB navigation <strong>without active state</strong> found</li>";
            }
        }
        return $messages;
    }

    /**
     * Check if there's main- & subnavigation present, and if so, check if they have an active state (CSS class)
     *
     * @param object $dom_obj of UL element in the HTML source of the page we're on, found by this->_checkNavActiveStates
     *
     * @return string if active state CSS class found
     */
    private function _findActiveState($dom_obj)
    {
        foreach ($dom_obj->getElementsByTagName('li') as $li) {
            if (strstr($li->getAttribute("class"), "active")) {
                return "active state found on listing tag";
            }
        }
        //or maybe it's accidentally on the a-tag??
        foreach ($dom_obj->getElementsByTagName('a') as $a) {
            if (strstr($a->getAttribute("class"), "active")) {
                return "active state found on a tag";
            }
        }
    }

    /**
     * Find favicon & stylesheet tags and check if their files are present!
     *
     * @param string $domain of the website we're on
     * @param object $dom_obj of the entire HTML source of the page we're on
     *
     * @return string HTML feedback list
     */
    private function _checkLinkTags($domain, $dom_obj)
    {
        $messages = "";
        $link_tags = $dom_obj->getElementsByTagName('link');
        $favicon_tag_found = false;
        $stylesheet_tag_found = false;
        $style_sheets = "";
        $stylesheets_good = true;
        foreach ($link_tags as $link) {
            $favicon_good = false;
            $stylesheet_good = false;
            switch ($link->getAttribute("rel")) {
                //favicon
                case "shortcut icon":
                    $favicon_good = true;
                    $favicon_tag_found = true;
                    $href = $link->getAttribute("href");
                    if (!empty($href)) {
                        if (!$this->_isDeadLink($domain, $href)) {
                            $favicon_file_message = "File <i>".$href."</i> found";
                            $favicon_good = true;
                        } else {
                            $favicon_file_message = "File <i>".$href."</i> NOT found";
                            $favicon_good = false;
                        }
                    } else {
                        $favicon_file_message = "<strong>Href</strong> attribute  EMPTY or NOT found";
                        $favicon_good = false;
                    }
                    $messages.= "<li style='color: ".($favicon_good? "green":"red")."'>Favicon tag present: ".$favicon_file_message."</li>";
                    break;
                //stylesheet
                case "stylesheet":
                    $stylesheet_good = true;
                    $stylesheet_tag_found = true;
                    $href = $link->getAttribute("href");
                    if (!empty($href)) {
                        if (!$this->_isDeadLink($domain, $href)) {
                            $stylesheet_file_message = "File <i>".$href."</i> found";
                            $stylesheet_good = true;
                        } else {
                            $stylesheet_file_message = "File <i>".$href."</i> NOT found";
                            $stylesheet_good = false;
                            $stylesheets_good = false;
                        }
                    } else {
                        $stylesheet_file_message = "<strong>Href</strong> attribute  EMPTY or NOT found";
                        $stylesheet_good = false;
                        $stylesheets_good = false;
                    }
                    $style_sheets.= "<li style='color: ".($stylesheet_good? "green":"red")."'>Stylesheet tag present: ".$stylesheet_file_message."</li>";
                    break;
            }
        }
        if (!$stylesheet_tag_found) { //we should at least have 1 stylesheet, right?
            $messages.= "<li style='color:red'><strong>NO Stylesheet</strong> file present</li>";
        } else {
            $messages.= "<li style='color:".($stylesheets_good?"green":"red")."'>Stylesheets:<ul>".$style_sheets."</ul></li>";
        }
        if (!$favicon_tag_found) {
            $messages.= "<li style='color:red'Favicon tag NOT present</li>";
        }
        return $messages;
    }

    /**
     * Find meta-description, -msapplication & -OG tags and check their content
     *
     * @param object $dom_obj of the entire HTML source of the page we're on
     *
     * @return string HTML feedback list
     */
    private function _checkMetaTags($dom_obj)
    {
        $meta_tags = $dom_obj->getElementsByTagName('meta');
        $meta_description_found = false;
        $meta_og_found = false;
        $meta_app_found = false;
        $messages = "";
        $meta_tag_messages = "";
        $good = true;
        $meta_tags_good = true;
        foreach ($meta_tags as $meta_tag) {
            $color = false;
            $meta_tag_message = "";
            $meta_content_message = ""; // filled by this->get_meta_content!
            //meta description tag
            if ("description" == $meta_tag->getAttribute("name")) {
                $meta_tag_message = "Meta description tag present: ";
                $meta_description_found = true;
                $good = true;
                $this->_getMetaContent($meta_tag, $meta_content_message, $good);
            }
            //meta msapplications tags
            if (preg_match("#msapplication#is", $meta_tag->getAttribute("name"))) {
                $meta_tag_message = "Meta ".$meta_tag->getAttribute("name")." tag found: ";
                $meta_app_found = true;
                $good = true;
                $this->_getMetaContent($meta_tag, $meta_content_message, $good);
            }
            //meta OG tags
            if (preg_match("#^og:#is", $meta_tag->getAttribute("property"))) {
                $meta_tag_message = "Meta ".$meta_tag->getAttribute("property")." tag found: ";
                $meta_og_found = true;
                $good = true;
                $this->_getMetaContent($meta_tag, $meta_content_message, $good);
            }
            if (!$good) {//if content attribute is missing or empty, we need to know!
                $meta_tags_good = false;
            }
            if ($meta_tag_message) {
                $meta_tag_messages.= "<li style='color: ".($good? "green":"red")."'>".$meta_tag_message.$meta_content_message."</li>";
            }
        }
        if (!$meta_description_found) {
            $meta_tag_messages.= "<li style='color:red'><strong>Meta description</strong> tag NOT present</li>";
            $meta_tags_good = false;
            $good = false;
        }
        if (!$meta_og_found) {
            $meta_tag_messages.= "<li style='color:red'><strong>Meta OG</strong> tag NOT present</li>";
            $meta_tags_good = false;
            $good = false;
        }
        if (!$meta_app_found) {
            $meta_tag_messages.= "<li style='color:red'><strong>Meta msapplication</strong> tag NOT present</li>";
            $meta_tags_good = false;
            $good = false;
        }
        $messages.= "<li style='color:".($meta_tags_good?"green":"red")."'>Meta-tags:<ul>".$meta_tag_messages."</ul></li>";
        return $messages;
    }

    /**
     * Used by meta-description & -msapplication & -OG tags in this->check_meta_tags()
     *
     * @param object $meta_tag_dom_obj of the meta tag in HTML source of the page we're on, found by this->_checkMetaTags
     * @param string $meta_content_message will be filled inside this function!
     * @param boolean $good will be set inside this function!
     *
     * @return void
     */
    private function _getMetaContent($meta_tag_dom_obj, &$meta_content_message, &$good)
    {
        $content = $meta_tag_dom_obj->getAttribute("content");
        if (!empty($content)) {
            $meta_content_message = "<i>".$content."</i>";
            $good = true;
        } else {
            $meta_content_message = "<strong>Content attribute</strong> EMPTY or NOT found!";
            $good = false;
        }
    }

    /**
     * Find script tags and check if their files are present!
     *
     * @param string $domain of the website we're on
     * @param object $dom_obj of the entire HTML source of the page we're on
     *
     * @return string HTML feedback list
     */
    private function _checkScriptTags($domain, $dom_obj)
    {
        $messages = "";
        $script_tags = $dom_obj->getElementsByTagName('script');
        $scripts_color = "green";
        foreach ($script_tags as $script_tag) {
            $script_file_message = "";
            $color = "red";
            $src = $script_tag->getAttribute("src");
            if (!empty($src)) {
                if (!$this->_isDeadLink($domain, $src)) {
                    $script_file_message = " File <i>".$src."</i> found";
                    $color = "green";
                } else {
                    $script_file_message = " File <i>".$src."</i> NOT found</li>";
                    $color = "red";
                    $scripts_color = $color;
                }
            } else {
                $script_file_message = " <strong>Src attribute</strong> EMPTY or NOT found!";
                $color = "orange";
            }
            $messages.= "<li style='color: ".$color."'>Script tag present: ".$script_file_message."</li>";
        }
        if ($messages) {
            return "<li style='color:".$scripts_color."'>Scripts:<ul>".$messages."</ul></li>";
        }
    }

    /**
     * Find img tags and check if their files are present!
     *
     * @param string $domain of the website we're on
     * @param object $dom_obj of the entire HTML source of the page we're on
     *
     * @return string HTML feedback list
     */
    private function _checkImgTags($domain, $dom_obj)
    {
        $messages = "";
        $img_tags = $dom_obj->getElementsByTagName('img');
        $images_good = true;
        foreach ($img_tags as $img_tag) {
            $good = false;
            $src = $img_tag->getAttribute("src");
            if (!empty($src)) {
                if (!$this->_isDeadLink($domain, $src)) {
                    $good = true;
                    $src_message = " File <i>".$src."</i> found,";
                } else {
                    $good = false;
                    $images_good = false;
                    $src_message = " File <i>".$src."</i> NOT found,";
                }
            } else {
                $src_message = " <strong>Src attribute</strong> EMPTY or NOT found!";
                $good = false;
                $images_good = false;
            }
            $alt = $img_tag->getAttribute("alt");
            if (!empty($alt)) {
                $good = ($good? true : false); //we cannot go back to good if it's already bad!
                $alt_message = " alt <i>".$alt."</i> found";
            } else {
                $alt_message = " <strong>alt attribute</strong> EMPTY or NOT found!";
                $good = false;
                $images_good = false;
            }
            $messages.= "<li style='color: ".($good? "green" : "red")."'>Img tag present:".$src_message.$alt_message."</li>";
        }
        if ($messages) {
            return "<li style='color:".($images_good?"green":"red")."'>Images:<ul>".$messages."</ul></li>";
        }
    }

    /**
     * Check forms for jQuery validate classes for input validation
     *
     * @param object $dom_obj of the entire HTML source of the page we're on
     *
     * @return string HTML feedback list
     */
    private function _checkFormValidation($dom_obj)
    {
        $messages = "";
        $form_messages = "";
        $good = true;
        foreach ($dom_obj->getElementsByTagName('form') as $form) {
            $form_validation_class_found = false;
            $form_class = $form->getAttribute("class");
            $form_id = $form->getAttribute("id");
            //Check for validation class
            if (!empty($form_class)) {
                //Name the form after its class if id not present
                if (empty($form_id)) {
                    $form_id = $form_class;
                }
                if (strstr($form_class, "validate")) {
                    $form_validation_class_found = true;
                } else {
                    $form_validation_class_found = false;
                    $good = false;
                }
            } else {
                $form_validation_class_found = false;
                $good = false;
            }
            //Let's check for required & validation classes
            $input_messages = "";
            foreach ($form->getElementsByTagName('input') as $input) {
                $input_requires_validation_class = false;
                $input_validation_class_found = "";
                $required_class_found = false;
                $input_name = $input->getAttribute("name");
                $input_class = $input->getAttribute("class");
                if (strstr($input_class, "required")) {
                    $required_class_found = true;
                }
                //EMAIL:
                if (strstr($input_name, "mail")) {
                    $input_requires_validation_class = "email";
                    if (strstr($input_class, $input_requires_validation_class)) {
                        $input_validation_class_found = $input_requires_validation_class;
                    } else {
                        $good = false;
                    }
                }
                //PHONE(NL)
                if (strstr($input_name, "tel") || strstr($input_name, "phone")) {
                    $input_requires_validation_class = "phoneNL";
                    if (strstr($input_class, $input_requires_validation_class)) {
                        $input_validation_class_found = $input_requires_validation_class;
                    } else {
                        $good = false;
                    }
                }
                //POSTAL CODE(NL):
                if (strstr($input_name, "post") || strstr($input_name, "zip")) {
                    $input_requires_validation_class = "postalcodeNL";
                    if (strstr($input_class, $input_requires_validation_class)) {
                        $input_validation_class_found = $input_requires_validation_class;
                    } else {
                        $good = false;
                    }
                }
                //IBAN:
                if (strstr($input_name, "iban")) {
                    $input_requires_validation_class = "iban";
                    if (strstr($input_class, $input_requires_validation_class)) {
                        $input_validation_class_found = $input_requires_validation_class;
                    } else {
                        $good = false;
                    }
                }
                //MOB(NL):
                if (strstr($input_name, "mob")) {
                    $input_requires_validation_class = "mobileNL";
                    if (strstr($input_class, $input_requires_validation_class)) {
                        $input_validation_class_found = $input_requires_validation_class;
                    } else {
                        $good = false;
                    }
                }
                if ($required_class_found || $input_validation_class_found) {
                    $input_messages.= "<li style='color:".($input_requires_validation_class? (!$input_validation_class_found? "red": "green") : "green")."'>";
                    $input_messages.= ($required_class_found? "<strong>Required</strong> i": "I")."nput <i>".$input_name."</i> found ";
                    $input_messages.= ($input_requires_validation_class? ($input_validation_class_found?"with correct (".$input_validation_class_found.")":"<strong>WITHOUT correct (".$input_requires_validation_class.")</strong>") : "").($input_requires_validation_class? " validation class" : "");
                    $input_messages.= "</li>";
                }
            }
            $form_messages.= "<li style='color:".($form_validation_class_found? "green":"red")."'>Form <i>".$form_id."</i>".($form_validation_class_found? " has" : " <strong>does NOT have</strong> ")." a validation class".($input_messages?"<ul>".$input_messages."</ul>":"")."</li>";
        }
        if ($form_messages) {
            return "<li style='color:".($good?"green":"red")."'>Forms:<ul>".$form_messages."</ul></li>";
        }
    }

    /**
     * Check Detectify rules
     *
     * @param string $domain of the site we're on
     * @param object $dom_obj of the entire HTML source of the page we're on
     *
     * @return string HTML feedback list
     */
    private function _checkDetectifyMeasures($domain, $dom_obj)
    {
        $messages = "";
        //check if mailto links have an attribute rel=noopener
        foreach ($dom_obj->getElementsByTagName("a") as $a) {
            $href = $a->getAttribute("href");
            //What do we call this link?
            $name = " ".$a->textContent;
            $class = $a->getAttribute("class");
            if (empty($content) && !empty($class)) {
                $name = ".".$class;
            }
            $id = $a->getAttribute("id");
            if (empty($content) && !empty($id)) {
                $name = "#".$id;
            }
            if (!empty($href)) {
                if (preg_match("#^mailto\:#", $href)) {
                    $rel = $a->getAttribute("rel");
                    if (!empty($rel)) {
                        $messages.= "<li style='color:green'>Mailto-link<strong>".$name."</strong> to <i>".$href."</i> has a rel='noopener' attribute (Detectify)</li>";
                    } else {
                        $messages.= "<li style='color:red'>Mailto-link<strong>".$name."</strong> to <i>".$href."</i> <strong>has no rel='noopener' attribute</strong> (Detectify)</li>";
                    }
                } elseif (!preg_match("#^\##", $href) && !preg_match("#^tel\:#i", $href) && !preg_match("#javascript()#i", $href)) { //Do NOT check anchors, te;-links or javascript!
                    if ($this->_isDeadLink($domain, $href)) {
                        $messages.= "<li style='color:red'>Link <strong>".$name."</strong> to <i>".$href."</i> <strong>does not exist</strong> (HTTP status code != 200|301|302)</li>";
                    }
                    //This is just too much!
                    //$messages.= "<li style='color:green'>Link<strong>".$name."</strong> to <i>".$href."</i> found</li>";
                }
            } else {
                $messages.= "<li style='color:red'>Link<strong>".$name."</strong> <strong>has no href attribute</strong></li>";
            }
        }
        if ($messages) {
            return "<li style='color:red'>Links:<ul>".$messages."</ul></li>";
        }
    }

    /**
     * Find mustache tags, excluding those inside inline script tagpairs, which could be unparsed twig or EE tags!
     *
     * @param string $html_source of the site we're on
     *
     * @return string HTML feedback list
     */
    private function _findStrayTags($html_source)
    {
        // $html_source = "<html><script>{ do not detect this tag }{{ do not detect this tag either }}</script><body>{{ detect_this_tag }}</body>{ detect_this_tag }</html>"; //test
        $messages = "";
        $html_source = preg_replace('/<\s*script.*?\/script\s*>/iu', '', $html_source); //remove scripttags!
        preg_match_all("#\{{1,2}[a-z0-9_\-\s]{3,50}\}{1,2}#is", $html_source, $matches);//32 is Drupal field maxlength
        foreach ($matches[0] as $match) {
            $messages.= "<li style='color:red'>Stray tag <i>".$match."</i> found</li>";
        }
        if ($messages) {
            return "<li style='color:red'>Stray tags:<ul>".$messages."</ul></li>";
        }
    }

    /**
     * Not an actual API, but who cares!
     * Assuming you made no typos in the tag <scrip type='application/ld+json'>!
     *
     * @param object $dom_obj of the entire page we're on
     *
     * @return string HTML feedback list
     */
    private function _testStructuredData($dom_obj)
    {
        $messages = "";
        $snippets = "";
        foreach ($dom_obj->getElementsByTagName("script") as $script) {
            if ("application/ld+json" == $script->getAttribute("type")) {
                $snippets.= "<script type='application/ld+json'>".$script->textContent."</script>";
            }
        }
        if ($snippets) {
            $result = $this->_curl("http://linter.structured-data.org/", json_encode(array("content" => $snippets)));
            $results = json_decode($result, true);
            foreach ($results['messages'] as $message) {
                $messages.= "<li style='color:red'>".$message."</li>";
            }
            if ($messages) {
                return "<h2>Structured data</h2><ul>".$messages."</ul>";
            }
        }
    }

    /**
     * Test pagespeed according to Google
     * Does not work when a cookiewall is present!
     *
     * @param string $page_url of the page we're on
     *
     * @return string HTML feedback list
     */
    private function _testPagespeed($page_url)
    {
        $strategies = array("mobile", "desktop");
        $strategy_feedbacks = array();
        $heading = "";
        $feedback = "";
        $error = "";
        foreach ($strategies as $strategy) {
            $result = $this->_curl("https://www.googleapis.com/pagespeedonline/v4/runPagespeed?url=".$page_url."&strategy=".$strategy);
            $pagespeed_array = json_decode($result, true);
            if (!isset($pagespeed_array["error"])) {
                $score = $pagespeed_array["ruleGroups"]["SPEED"]["score"];
                $color = ($score>75? "green" : ($score > 50? "orange" : "red" ));
                $strategy_feedbacks[$score] = "";
                if (!empty($heading)) {
                    $heading.= " /";
                } else {
                    $heading.= " -";
                }
                $heading.= " <span style='color:".$color."'>".ucfirst($strategy)." score: ".$score."</span>";
                foreach ($pagespeed_array["formattedResults"]["ruleResults"] as $key => $rule_result) {
                    $extra_info = "";
                    $summary = $rule_result["summary"]["format"];
                    if (isset($rule_result["summary"]["args"][0]["value"]) && "LINK" == $rule_result["summary"]["args"][0]["key"]) {
                        $link = $rule_result["summary"]["args"][0]["value"];
                        $summary = str_replace(array("{{BEGIN_LINK}}", "{{END_LINK}}"), array("<a href='".$link."' target='_blank'>", "</a>"), $summary);
                    }
                    if (isset($rule_result["summary"]["args"][0]["value"]) && "RESPONSE_TIME" == $rule_result["summary"]["args"][0]["key"]) {
                        $response_time = "<strong>".$rule_result["summary"]["args"][0]["value"]."</strong>";
                        $summary = str_replace(array("{{RESPONSE_TIME}}"), array($response_time), $summary);
                    }
                    if (isset($rule_result["summary"]["args"][0]["value"]) && "NUM_CSS" == $rule_result["summary"]["args"][0]["key"]) {
                        $num_css = "<strong>".$rule_result["summary"]["args"][0]["value"]."</strong>";
                        $summary = str_replace(array("{{NUM_CSS}}"), array($num_css), $summary);
                        if (isset($rule_result["urlBlocks"][1]["urls"])) {
                            foreach ($rule_result["urlBlocks"][1]["urls"] as $url) {
                                if (isset($url["result"]["args"][0]["value"])) {
                                    $extra_info.= "<li style='color:".$color."'>".$url["result"]["args"][0]["value"]."</li>";
                                }
                            }
                        }
                    }
                    $strategy_feedbacks[$score].= "<li style='color:".$color."'>".$rule_result["localizedRuleName"].": ".$summary.(!empty($extra_info)? "<ul>".$extra_info."</ul>": "")."</li>";
                }
            } else {
                $error = "<li style='color:red'>".$pagespeed_array["error"]["message"]."</li>";
            }
        }
        $feedback = "<h2>Pagespeed test".$heading."</h2>";
        if (count($strategy_feedbacks)>0) {
            //get the feedback for the lowest score! (They are similar most of the time)
            $feedback.= "<ul>".$strategy_feedbacks[min(array_keys($strategy_feedbacks))]."</ul>";
        } else {
            $feedback.= "<ul>".$error."</ul>";
        }
        return $feedback;
    }
}
