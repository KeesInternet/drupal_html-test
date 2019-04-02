<?php
    namespace Drupal\html_checker\EventSubscriber;
    /**
     * @file
     * Contains \Drupal\my_event_subscriber\EventSubscriber\MyEventSubscriber.
     */

    use Drupal;
    use DOMDocument;
    use Symfony\Component\HttpKernel\KernelEvents;
    use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
    use Symfony\Component\EventDispatcher\EventSubscriberInterface;

    /**
     * Event Subscriber MyEventSubscriber.
     */
    class HtmlCheckEventSubscriber implements EventSubscriberInterface {

        /**
         * Code that should be triggered on event specified
         */
        public function onRespond(FilterResponseEvent $event) {

            if (isset($_GET['test_html']) && 1 == \Drupal::currentUser()->id()) {
                error_reporting(E_ALL);
                ini_set('display_errors', 1);
                //no trailing slash!
                $domain = "http".( "on" == $_SERVER["HTTPS"] ? "s" : "" )."://".$_SERVER["SERVER_NAME"];
                //Use URL per template specific testing!
                $test_uri = explode("?", $_SERVER["REQUEST_URI"])[0];
                $table_headers = "<th>".$domain.$test_uri."</th>";
                $table_data = "<td>";
                $table_data.= "<h2>W3C validation</h2>".$this->validate_html($domain.$test_uri);
                $table_data.= $this->accessability_checker($domain.$test_uri);
                $html_source = "";//this variable will be filled after the get_dom_obj function!
                $dom_obj = $this->get_dom_obj($domain, $test_uri, $html_source);
                //Test rich snippets if present
                $table_data.= $this->test_structured_data($dom_obj);
                if(!$dom_obj){
                    $table_data.= "<li>Header did NOT return 200!</li>";
                }else{
                    $table_data.= $this->check_source($dom_obj, $domain, $html_source);
                }
                $table_data.= $this->test_pagespeed($domain.$test_uri);
                $table_data.= "</td>";
                $table = "<table cellpadding='10' border='1'><tr>".$table_headers."</tr><tr>".$table_data."</tr></table>";
                echo $table; exit;
            }
        }

        /**
         * {@inheritdoc}
         */
        public static function getSubscribedEvents()
        {
            // For this example I am using KernelEvents constants (see below a full list).
            $events[KernelEvents::RESPONSE][] = ['onRespond'];
            return $events;
        }
        
        //does not work when cookiewall is present!
        private function validate_html($test_uri){
            $messages = "<ul>";
            $result = $this->curl('https://validator.w3.org/nu/?out=json&doc='.$test_uri);
            $array = json_decode($result,TRUE);
            foreach($array["messages"] as $key => $message){
                //only show errors if an actual message is present!
                $messages.= (!empty($message["message"])? "<li style='color: red'>".$message["message"].(!empty($message['extract'])? ": <i>".htmlspecialchars($message['extract']) : "")."</i></li>" : "");
            }
            $messages.= "</ul>";
            return $messages;
        }

        //Used by this->validate_html() & this->get_dom_obj() & this->test_pagespeed() & this->accessability_checker() & this->test_structured_data() & this->dead_link()!!!
        private function curl($url, $data_string="", $set_cookie="", $get_http_status=""){
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_HEADER, ($get_http_status? TRUE:FALSE)); //still returning header?
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
            //Disable SSL verification!
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
            //post fields if present
            if(!empty($data_string)){
                curl_setopt($curl,CURLOPT_POST, TRUE);
                curl_setopt($curl,CURLOPT_POSTFIELDS, $data_string);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array(                                                                          
                    'Content-Type: application/json',                                                                             
                    'Content-Length: ' . strlen($data_string))                                                                       
                ); 
            }
            if($get_http_status){
                curl_setopt($curl,CURLOPT_NOBODY, TRUE);
            }
            //Expressionengine uses these options
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($curl, CURLOPT_VERBOSE, TRUE);
            curl_setopt($curl, CURLOPT_USERAGENT,'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.52 Safari/537.17');
            //Pass the KEES tm cookiewall
            if($set_cookie){
                curl_setopt($curl, CURLOPT_HTTPHEADER, array("Cookie: cookiewall=accept"));
            }
            $return_data = curl_exec($curl);
            // Check for errors and display the error message
            if($errno = curl_errno($curl)) {
                $error_message = curl_strerror($errno);
                echo "cURL error ({$errno}):\n {$error_message}";
            }
            if($get_http_status){
                $return_data = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            }
            curl_close($curl);
            return $return_data;
        }

        //Does not work when a cookiewall is present!
        private function accessability_checker($uri){
            $web_service_id = "d57cb24c0f3a18f7674f2a12d26062409f75272d";
            $result = $this->curl("https://achecker.ca/checkacc.php?uri=".$uri."&id=".$web_service_id."&output=rest&guide=WCAG2-AA");
            $xml = simplexml_load_string($result, "SimpleXMLElement", LIBXML_NOCDATA);
            $json = json_encode($xml);
            $array = json_decode($json,TRUE);
            $status = $array["summary"]["status"];
            $message = "<h2>Accessability (guideline ".$array["summary"]["guidelines"]["guideline"].") status: <span style='color:".("FAIL" == $status? "red":"green")."'>".$status."</span></h2>";
            $messages = "";
            if(isset($array["results"]["result"])){
                foreach($array["results"]["result"] as $result){
                    if("Error" == $result["resultType"]){
                        $messages.= "<li style='color:red'>".$result["errorMsg"].": <i>".htmlspecialchars($result["errorSourceCode"])."</i></li>";
                    }
                }
            }
            if($messages){
                $message.= "<ul>".$messages."</ul>";
            }
            return $message;
        }

        //Get DOM object & HTML source!
        private function get_dom_obj($domain, $test_uri, &$html_source){
            //Is this URL available?
            if($this->dead_link($domain, $test_uri)){
                return false;
            }else{
                $html_source = $this->curl($domain.$test_uri, false, true);
                libxml_use_internal_errors(true);
                $dom_obj = new DOMDocument();
                $dom_obj->loadHTML($html_source);
                return $dom_obj;
            }
        }

        //used by this->__construct() & this->check_link_tags() & this->check_script_tags() & this->check_img_tags() & this->check_detectify_measures()
        private function dead_link($domain, $url){
            //Check if it's a  local or remote (or even protocol relative!) URL so we can see if the content actually exists
            $url = (strstr($url, "http")? "": (preg_match("#^\/\/#", $url)? "https:": $domain)).$url;
            $http_status = $this->curl($url, false, false, true);
            if(!in_array($http_status, ["200", "301", "302"])){
                return true;
            }else{
                return false;
            }
        }

        private function check_source($dom_obj, $domain, $html_source){
            //Check semantic structure
            $messages = $this->check_structure_headings($dom_obj);
            $messages.= "<h2>Custom checks</h2>";
            $messages.= "<ul>";
            $messages.= $this->check_for_single_h1($dom_obj);
            //Check for active states in main- & sub-nav!
            $messages.= $this->check_nav_active_states($dom_obj);
            //Check favicon & css tags
            $messages.= $this->check_link_tags($domain, $dom_obj);
            //Check description- msapplications- & OG-tags
            $messages.= $this->check_meta_tags($dom_obj);
            //Check javascript tags
            $messages.= $this->check_script_tags($domain, $dom_obj);
            //Check images (src & alt)
            $messages.= $this->check_img_tags($domain, $dom_obj);
            //Check forms for validation classes (jQuery validate)
            $messages.= $this->check_form_validation($dom_obj);
            //Check Detectify measures
            $messages.= $this->check_detectify_measures($domain, $dom_obj);
            //Find stray (non inline javascript) twig/EE tags
            $messages.= $this->find_stray_tags($html_source);
            $messages.= "</ul>";
            return $messages;
        }

        private function check_structure_headings($dom_obj){
            $heading_requiring_elements = Array("header", "section", "article", "nav", "aside", "footer");
            $structure = $this->find_structure($dom_obj, $heading_requiring_elements);
            return "<h2>Semantic structure</h2><ul>".$this->show_structure($structure)."</ul>";
        }

        private function find_structure($dom_obj, $heading_requiring_elements){
            $headings = Array();
            $sub_structure = Array();
            $found_elements = Array();
            if($dom_obj->hasChildNodes()){
                foreach($dom_obj->childNodes as $child_node){
                    $found_element = Array();
                    if(!preg_match("#h\d+#i", $child_node->nodeName)){ //not a header!
                        if(in_array($child_node->nodeName, $heading_requiring_elements)){//we're looking for this element!
                            $element_found = true;
                            $found_element["element"] = $child_node->nodeName;
                            $found_element["selector"] = ($child_node->getAttribute("id")? "#".$child_node->getAttribute("id"):($child_node->getAttribute("class")? ".".$child_node->getAttribute("class") : ""));
                            //now let's find it's header inside!
                            $element_sub_structure = $this->find_structure($child_node, $heading_requiring_elements);
                            if(!empty($element_sub_structure)){
                                $found_element["sub_structure"] = $element_sub_structure;
                            }
                            $found_elements[] = $found_element;
                        }else{//NOT the element we are looking for, but maybe there's one inside?
                            $found_structure = $this->find_structure($child_node, $heading_requiring_elements);
                            if(!empty($found_structure)){
                                $sub_structure[] = $found_structure;
                            }
                        }
                    }elseif(preg_match("#h\d+#i", $child_node->nodeName)){ //this is a header!
                        $headings[] = Array("selector" => $child_node->nodeName, "value" => $child_node->nodeValue);
                    }
                }
            }
            $structure = Array();
            if(count($headings) > 0){
                $structure["headings"] = $headings;
            }
            if(count($found_elements) > 0){
                $structure = array_merge($structure, $found_elements);
            }
            if(count($sub_structure) > 0){
                if(count($sub_structure) == 1){
                    $sub_structure = $sub_structure[0];
                }
                $structure = array_merge($structure, $sub_structure);
            }
            return $structure;
        }

        private function show_structure($structure){
            $list = "";
            if(is_array($structure)){
                foreach($structure as $key => $node){
                    if(isset($node["element"]) && isset($node["selector"])){
                        $list.= "<li style='color:green'>";
                        $list.= $node["element"]."<i>".$node["selector"]."</i>";
                        $list.= "<ul>";
                        if(isset($node["sub_structure"]["headings"])){
                            // $list.= "<li>Single heading:</li>"; 
                            $list.= $this->show_structure($node);
                        }elseif(isset($node["sub_structure"][0]["headings"])){
                            // $list.= "<li>Multiple headings:</li>"; 
                            $list.= $this->show_structure($node["sub_structure"]);
                        }
                        $list.= "</ul>";
                        $list.= "</li>";
                    }elseif(isset($node["headings"])){
                        foreach($node["headings"] as $heading){
                            $list.= "<li style='color:green'>".$heading["selector"]." <i>".($heading["value"]? $heading["value"] : "-empty-")."</i></li>";
                        }
                        // $list.= "<li>Elements after heading:</li>"; 
                        $list.= $this->show_structure($node);
                    }else{
                        // $list.= "<li>More elements:</li>"; 
                        $list.= $this->show_structure($node);
                    }
                }
            }
            return $list;
        }

        private function check_for_single_h1($dom_obj){
            $messages= "";
            $h1_tags = $dom_obj->getElementsByTagName('h1');
            if(isset($h1_tags->length)){
                if($h1_tags->length == 0){
                    return "<li style='color:red'>NO H1 tag found!</li>";
                }elseif($h1_tags->length == 1){
                    foreach($h1_tags as $h1_tag){
                        return "<li style='color:green'>Single H1 <i>".($h1_tag->nodeValue? $h1_tag->nodeValue : "-empty-")."</i> tag found</li>";
                    }
                }elseif($h1_tags->length > 1){
                    $messages.= "<li style='color:red'>".$h1_tags->length." H1 tags found:<ul>";
                    foreach($h1_tags as $h1_tag){
                        $messages.= "<li><i>".($h1_tag->nodeValue? $h1_tag->nodeValue : "-empty-")."</i></li>";
                    }
                    $messages.="</ul>There should only be one!</li>";
                    return $messages;
                }
            }
        }

        private function check_nav_active_states($dom_obj){
            //main nav
            $messages = "";
            $mainnav_message = "";
            foreach($dom_obj->getElementsByTagName('header') as $header){
                $mainnav_message = $this->find_active_state($header);
            }
            if(empty($mainnav_message)){
                $messages = "<li style='color:red'><strong>NO</strong> main navigation active states found on a or listing tags</li>";
            }else{
                $messages = "<li style='color:green'>Main navigation ".$mainnav_message."</li>";
            }
            //subnav present?
            $subnav_message = "";
            $subnav_found = false;
            //Try to detect the subnav by ul-classname
            foreach($dom_obj->getElementsByTagName('ul') as $ul){
                if(strstr($ul->getAttribute("class"), "nav")){
                    $subnav_found = true;
                    $subnav_message = $this->find_active_state($ul);
                    if(!empty($subnav_message)){ break; }
                }
            }
            //No subnav found yet? Try to detect the subnav by ul id!
            if(!$subnav_found){
                foreach($dom_obj->getElementsByTagName('ul') as $ul){            
                    if(strstr($ul->getAttribute("id"), "nav")){
                        $subnav_found = true;
                        $subnav_message = $this->find_active_state($ul);
                        if(!empty($subnav_message)){ break; }
                    }
                }
            }
            if($subnav_found){
                if(!empty($subnav_message)){
                    $messages.= "<li style='color:green'>SUB navigation ".$subnav_message."</li>";  
                }else{
                    $messages.= "<li style='color:red'>SUB navigation <strong>without active state</strong> found</li>"; 
                }
            }
            return $messages;
        }

        private function find_active_state($dom_obj){
            foreach($dom_obj->getElementsByTagName('li') as $li){
                if(strstr($li->getAttribute("class"), "active")){ 
                    return "active state found on listing tag";
                }
            }
            //or maybe it's accidentally on the a-tag??
            foreach($dom_obj->getElementsByTagName('a') as $a){
                if(strstr($a->getAttribute("class"), "active")){ 
                    return "active state found on a tag";
                }
            }
        }

        //Find favicon & stylesheet tags and check if their files are present!
        private function check_link_tags($domain, $dom_obj){
            $messages = "";
            $link_tags = $dom_obj->getElementsByTagName('link');
            $favicon_tag_found = false;
            $stylesheet_tag_found = false;
            $style_sheets = "";
            $stylesheets_good = true;
            foreach($link_tags as $link){
                $favicon_good = false;
                $stylesheet_good = false;
                switch($link->getAttribute("rel")){
                    //favicon
                    case "shortcut icon":
                        $favicon_good = true;
                        $favicon_tag_found = true;
                        $href = $link->getAttribute("href");
                        if(!empty($href)){
                            if(!$this->dead_link($domain, $href)){ 
                                $favicon_file_message = "File <i>".$href."</i> found";
                                $favicon_good = true;
                            }else{
                                $favicon_file_message = "File <i>".$href."</i> NOT found";
                                $favicon_good = false;
                            }
                        }else{
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
                        if(!empty($href)){
                            if(!$this->dead_link($domain, $href)){ 
                                $stylesheet_file_message = "File <i>".$href."</i> found";
                                $stylesheet_good = true;
                            }else{
                                $stylesheet_file_message = "File <i>".$href."</i> NOT found";
                                $stylesheet_good = false;
                                $stylesheets_good = false;
                            }
                        }else{
                            $stylesheet_file_message = "<strong>Href</strong> attribute  EMPTY or NOT found";
                            $stylesheet_good = false;
                            $stylesheets_good = false;
                        }
                        $style_sheets.= "<li style='color: ".($stylesheet_good? "green":"red")."'>Stylesheet tag present: ".$stylesheet_file_message."</li>";
                        break;
                }
            }
            if(!$stylesheet_tag_found){ //we should at least have 1 stylesheet, right?
                $messages.= "<li style='color:red'><strong>NO Stylesheet</strong> file present</li>";
            }else{
                $messages.= "<li style='color:".($stylesheets_good?"green":"red")."'>Stylesheets:<ul>".$style_sheets."</ul></li>";
            }
            if(!$favicon_tag_found){
                $messages.= "<li style='color:red'Favicon tag NOT present</li>";
            }        
            return $messages;
        }

        //Find meta-description, -msapplication & -OG tags and check their content
        private function check_meta_tags($dom_obj){
            $meta_tags = $dom_obj->getElementsByTagName('meta');
            $meta_description_found = false;
            $meta_og_found = false;
            $meta_app_found = false;
            $messages = "";
            $meta_tag_messages = "";
            $good = true;
            $meta_tags_good = true;
            foreach($meta_tags as $meta_tag){
                $color = false;
                $meta_tag_message = "";
                $meta_content_message = ""; // filled by this->get_meta_content!
                //meta description tag
                if("description" == $meta_tag->getAttribute("name")){
                    $meta_tag_message = "Meta description tag present: ";
                    $meta_description_found = true;
                    $good = true;   
                    $this->get_meta_content($meta_tag, $meta_content_message, $good);
                }
                //meta msapplications tags
                if(preg_match("#msapplication#is", $meta_tag->getAttribute("name"))){
                    $meta_tag_message = "Meta ".$meta_tag->getAttribute("name")." tag found: ";
                    $meta_app_found = true;
                    $good = true;
                    $this->get_meta_content($meta_tag, $meta_content_message, $good);
                }
                //meta OG tags
                if(preg_match("#^og:#is",$meta_tag->getAttribute("property"))){
                    $meta_tag_message = "Meta ".$meta_tag->getAttribute("property")." tag found: ";
                    $meta_og_found = true;
                    $good = true;
                    $this->get_meta_content($meta_tag, $meta_content_message, $good);
                }
                if(!$good){//if content attribute is missing or empty, we need to know!
                    $meta_tags_good = false;
                }
                if($meta_tag_message){
                    $meta_tag_messages.= "<li style='color: ".($good? "green":"red")."'>".$meta_tag_message.$meta_content_message."</li>";
                }
            }
            if(!$meta_description_found){
                $meta_tag_messages.= "<li style='color:red'><strong>Meta description</strong> tag NOT present</li>";
                $meta_tags_good = false;
                $good = false;
            }
            if(!$meta_og_found){
                $meta_tag_messages.= "<li style='color:red'><strong>Meta OG</strong> tag NOT present</li>";
                $meta_tags_good = false;
                $good = false;
            }
            if(!$meta_app_found){
                $meta_tag_messages.= "<li style='color:red'><strong>Meta msapplication</strong> tag NOT present</li>";
                $meta_tags_good = false;
                $good = false;
            }
            $messages.= "<li style='color:".($meta_tags_good?"green":"red")."'>Meta-tags:<ul>".$meta_tag_messages."</ul></li>";
            return $messages;
        }

        //Used by meta-description & -msapplication & -OG tags in this->check_meta_tags()
        private function get_meta_content($meta_tag, &$meta_content_message, &$good){
            $content = $meta_tag->getAttribute("content");
            if(!empty($content)){
                $meta_content_message = "<i>".$content."</i>";
                $good = true;
            }else{
                $meta_content_message = "<strong>Content attribute</strong> EMPTY or NOT found!";
                $good = false;  
            }
        }

        //Find script tags and check if their files are present!
        private function check_script_tags($domain, $dom_obj){
            $messages = "";
            $script_tags = $dom_obj->getElementsByTagName('script');
            $scripts_color = "green";
            foreach($script_tags as $script_tag){
                $script_file_message = "";
                $color = "red";
                $src = $script_tag->getAttribute("src");
                if(!empty($src)){
                    if(!$this->dead_link($domain, $src)){ 
                        $script_file_message = " File <i>".$src."</i> found";
                        $color = "green";
                    }else{
                        $script_file_message = " File <i>".$src."</i> NOT found</li>";
                        $color = "red";
                        $scripts_color = $color;
                    }
                }else{
                    $script_file_message = " <strong>Src attribute</strong> EMPTY or NOT found!";
                    $color = "orange";  
                }
                $messages.= "<li style='color: ".$color."'>Script tag present: ".$script_file_message."</li>";
            }
            if($messages){
                return "<li style='color:".$scripts_color."'>Scripts:<ul>".$messages."</ul></li>";
            }
        }

        //Find img tags and check if their files are present!
        private function check_img_tags($domain, $dom_obj){
            $messages = "";
            $img_tags = $dom_obj->getElementsByTagName('img');
            $images_good = true;
            foreach($img_tags as $img_tag){
                $good = false;
                $src = $img_tag->getAttribute("src");
                if(!empty($src)){
                    if(!$this->dead_link($domain, $src)){ 
                        $good = true;
                        $src_message = " File <i>".$src."</i> found,";
                    }else{
                        $good = false;
                        $images_good = false;
                        $src_message = " File <i>".$src."</i> NOT found,";
                    }
                }else{
                    $src_message = " <strong>Src attribute</strong> EMPTY or NOT found!";
                    $good = false;     
                    $images_good = false;
                }
                $alt = $img_tag->getAttribute("alt");
                if(!empty($alt)){
                    $good = ($good? true : false); //we cannot go back to good if it's already bad!
                    $alt_message = " alt <i>".$alt."</i> found";
                }else{
                    $alt_message = " <strong>alt attribute</strong> EMPTY or NOT found!";
                    $good = false;   
                    $images_good = false;  
                }
                $messages.= "<li style='color: ".($good? "green" : "red")."'>Img tag present:".$src_message.$alt_message."</li>";
            }
            if($messages){
                return "<li style='color:".($images_good?"green":"red")."'>Images:<ul>".$messages."</ul></li>";
            }
        }

        //Check forms for required & validation classes
        private function check_form_validation($dom_obj){
            $messages = "";
            $form_messages = "";
            $good = true;
            foreach($dom_obj->getElementsByTagName('form') as $form){
                $form_validation_class_found = false;
                $form_class = $form->getAttribute("class");
                $form_id = $form->getAttribute("id");
                //Check for validation class
                if(!empty($form_class)){
                    //Name the form after its class if id not present
                    if(empty($form_id)){
                        $form_id = $form_class;
                    }
                    if(strstr($form_class, "validate")){
                        $form_validation_class_found = true;
                    }else{
                        $form_validation_class_found = false;
                        $good = false;
                    }
                }else{
                    $form_validation_class_found = false;
                    $good = false;
                }
                //Let's check for required & validation classes
                $input_messages = "";
                foreach($form->getElementsByTagName('input') as $input){
                    $input_requires_validation_class = false;
                    $input_validation_class_found = "";
                    $required_class_found = false;
                    $input_name = $input->getAttribute("name");
                    $input_class = $input->getAttribute("class");
                    if(strstr($input_class, "required")){ 
                        $required_class_found = true;
                    }
                    //EMAIL:
                    if(strstr($input_name, "mail")){
                        $input_requires_validation_class = "email";
                        if(strstr($input_class, $input_requires_validation_class)){ 
                            $input_validation_class_found = $input_requires_validation_class;
                        }else{
                            $good = false;
                        }
                    }
                    //PHONE(NL)
                    if(strstr($input_name, "tel") || strstr($input_name, "phone")){
                        $input_requires_validation_class = "phoneNL";                    
                        if(strstr($input_class, $input_requires_validation_class)){ 
                            $input_validation_class_found = $input_requires_validation_class;
                        }else{
                            $good = false;
                        }
                    }
                    //POSTAL CODE(NL):
                    if(strstr($input_name, "post") || strstr($input_name, "zip")){
                        $input_requires_validation_class = "postalcodeNL";
                        if(strstr($input_class, $input_requires_validation_class)){ 
                            $input_validation_class_found = $input_requires_validation_class;
                        }else{
                            $good = false;
                        }
                    }
                    //IBAN:
                    if(strstr($input_name, "iban")){
                        $input_requires_validation_class = "iban";
                        if(strstr($input_class, $input_requires_validation_class)){ 
                            $input_validation_class_found = $input_requires_validation_class;
                        }else{
                            $good = false;
                        }
                    }
                    //MOB(NL):
                    if(strstr($input_name, "mob")){
                        $input_requires_validation_class = "mobileNL";
                        if(strstr($input_class, $input_requires_validation_class)){ 
                            $input_validation_class_found = $input_requires_validation_class;
                        }else{
                            $good = false;
                        }
                    }
                    if($required_class_found || $input_validation_class_found){
                        $input_messages.= "<li style='color:".($input_requires_validation_class? (!$input_validation_class_found? "red": "green") : "green")."'>";
                        $input_messages.= ($required_class_found? "<strong>Required</strong> i": "I")."nput <i>".$input_name."</i> found ";
                        $input_messages.= ($input_requires_validation_class? ($input_validation_class_found?"with correct (".$input_validation_class_found.")":"<strong>WITHOUT correct (".$input_requires_validation_class.")</strong>") : "").($input_requires_validation_class? " validation class" : "");
                        $input_messages.= "</li>";
                    }
                }
                $form_messages.= "<li style='color:".($form_validation_class_found? "green":"red")."'>Form <i>".$form_id."</i>".($form_validation_class_found? " has" : " <strong>does NOT have</strong> ")." a validation class".($input_messages?"<ul>".$input_messages."</ul>":"")."</li>";
            }
            if($form_messages){
                return "<li style='color:".($good?"green":"red")."'>Forms:<ul>".$form_messages."</ul></li>";
            }
        }

        private function check_detectify_measures($domain, $dom_obj){
            $messages = "";
            //check if mailto links have an attribute rel=noopener
            foreach($dom_obj->getElementsByTagName("a") as $a){
                $href = $a->getAttribute("href");
                //What do we call this link?
                $name = " ".$a->textContent;
                $class = $a->getAttribute("class");
                if(empty($content) && !empty($class)){
                    $name = ".".$class;
                }
                $id = $a->getAttribute("id");
                if(empty($content) && !empty($id)){
                    $name = "#".$id;
                }
                if(!empty($href)){
                    if(preg_match("#^mailto\:#", $href)){
                        $rel = $a->getAttribute("rel");
                        if(!empty($rel)){
                            $messages.= "<li style='color:green'>Mailto-link<strong>".$name."</strong> to <i>".$href."</i> has a rel='noopener' attribute (Detectify)</li>";
                        }else{
                            $messages.= "<li style='color:red'>Mailto-link<strong>".$name."</strong> to <i>".$href."</i> <strong>has no rel='noopener' attribute</strong> (Detectify)</li>";
                        }
                    }else{
                        if($this->dead_link($domain, $href)){ 
                            $messages.= "<li style='color:red'>Link <strong>".$name."</strong> to <i>".$href."</i> <strong>does not exist</strong> (HTTP status code != 200|301|302)</li>";
                        }
                        //This is just too much!
                        //$messages.= "<li style='color:green'>Link<strong>".$name."</strong> to <i>".$href."</i> found</li>"; 
                    }
                }else{
                    $messages.= "<li style='color:red'>Link<strong>".$name."</strong> <strong>has no href attribute</strong></li>";
                }
            }
            if($messages){
                return "<li style='color:red'>Links:<ul>".$messages."</ul></li>";
            }
        }

        //Find mustache tags, excluding those inside inline script tagpairs, which could be unparsed twig or EE tags!
        private function find_stray_tags($html_source){
            // $html_source = "<html><script>{ do not detect this tag }{{ do not detect this tag either }}</script><body>{{ detect_this_tag }}</body>{ detect_this_tag }</html>"; //test
            $messages = "";
            $html_source = preg_replace('/<\s*script.*?\/script\s*>/iu', '', $html_source); //remove scripttags!
            preg_match_all("#\{{1,2}[a-z0-9_\-\s]{3,50}\}{1,2}#is", $html_source, $matches);//32 is Drupal field maxlength
            foreach($matches[0] as $match){
                $messages.= "<li style='color:red'>Stray tag <i>".$match."</i> found</li>";
            }
            if($messages){
                return "<li style='color:red'>Stray tags:<ul>".$messages."</ul></li>";
            }
        }

        //Not an actual API, but who cares!
        //Assuming you made no typos in the tag <scrip type='application/ld+json'>!
        private function test_structured_data($dom_obj){
            $messages = "";
            $snippets = "";
            foreach($dom_obj->getElementsByTagName("script") as $script){
                if("application/ld+json" == $script->getAttribute("type")){
                    $snippets.= "<script type='application/ld+json'>".$script->textContent."</script>";
                }
            }
            if($snippets){
                $result = $this->curl("http://linter.structured-data.org/", json_encode(Array("content" => $snippets)));
                $array = json_decode($result, true);
                foreach($array['messages'] as $message){
                    $messages.= "<li style='color:red'>".$message."</li>";
                }
                if($messages){
                    return "<h2>Structured data</h2><ul>".$messages."</ul>";
                }
            }
        }

        //Does not work when a cookiewall is present!
        private function test_pagespeed($page_url){
            $strategies = Array("mobile", "desktop");
            $strategy_feedbacks = Array();
            $heading = "";
            $feedback = "";
            $error = "";
            foreach($strategies as $strategy){
                $result = $this->curl("https://www.googleapis.com/pagespeedonline/v4/runPagespeed?url=".$page_url."&strategy=".$strategy);
                $pagespeed_array = json_decode($result, true);
                if(!isset($pagespeed_array["error"])){
                    $score = $pagespeed_array["ruleGroups"]["SPEED"]["score"];
                    $color = ($score>75? "green" : ($score > 50? "orange" : "red" ));
                    $strategy_feedbacks[$score] = "";
                    if(!empty($heading)){
                        $heading.= " /";
                    }else{
                        $heading.= " -";
                    }
                    $heading.= " <span style='color:".$color."'>".ucfirst($strategy)." score: ".$score."</span>";
                    foreach($pagespeed_array["formattedResults"]["ruleResults"] as $key => $rule_result){
                        $extra_info = "";
                        $summary = $rule_result["summary"]["format"];
                        if(isset($rule_result["summary"]["args"][0]["value"]) && "LINK" == $rule_result["summary"]["args"][0]["key"]){
                            $link = $rule_result["summary"]["args"][0]["value"];
                            $summary = str_replace(Array("{{BEGIN_LINK}}", "{{END_LINK}}"), Array("<a href='".$link."' target='_blank'>", "</a>"), $summary);
                        }
                        if(isset($rule_result["summary"]["args"][0]["value"]) && "RESPONSE_TIME" == $rule_result["summary"]["args"][0]["key"]){
                            $response_time = "<strong>".$rule_result["summary"]["args"][0]["value"]."</strong>";
                            $summary = str_replace(Array("{{RESPONSE_TIME}}"), Array($response_time), $summary);
                        }
                        if(isset($rule_result["summary"]["args"][0]["value"]) && "NUM_CSS" == $rule_result["summary"]["args"][0]["key"]){
                            $num_css = "<strong>".$rule_result["summary"]["args"][0]["value"]."</strong>";
                            $summary = str_replace(Array("{{NUM_CSS}}"), Array($num_css), $summary);
                            if(isset($rule_result["urlBlocks"][1]["urls"])){
                                foreach($rule_result["urlBlocks"][1]["urls"] as $url){
                                    if(isset($url["result"]["args"][0]["value"])){
                                        $extra_info.= "<li style='color:".$color."'>".$url["result"]["args"][0]["value"]."</li>";
                                    }
                                }
                            }
                        }
                        $strategy_feedbacks[$score].= "<li style='color:".$color."'>".$rule_result["localizedRuleName"].": ".$summary.(!empty($extra_info)? "<ul>".$extra_info."</ul>": "")."</li>";
                    }
                }else{
                    $error = "<li style='color:red'>".$pagespeed_array["error"]["message"]."</li>";
                }
            }
            $feedback = "<h2>Pagespeed test".$heading."</h2>";
            if(count($strategy_feedbacks)>0){
                //get the feedback for the lowest score! (They are similar most of the time)
                $feedback.= "<ul>".$strategy_feedbacks[min(array_keys($strategy_feedbacks))]."</ul>";
            }else{
                $feedback.= "<ul>".$error."</ul>";
            }
            return $feedback;
        }
        


    }
