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
                $table_headers = "<th><big style='color:red;'><b><p>For optimal validation use this on the Acceptance/Production environment</p></b></big>".$domain.$test_uri."</th>";
                $table_data = "<td>";
                $table_data.= "<h2>W3C validation</h2>".$this->validate_html($domain.$test_uri);
                $html_source = "";//this variable will be filled after the get_dom_obj function!
                $dom_obj = $this->get_dom_obj($domain.$test_uri, $html_source);
                if(!$dom_obj){
                    $table_data.= "<li>Header did NOT return 200!</li>";
                }else{
                    $table_data.= $this->check_source($dom_obj, $domain, $html_source);
                }
                $table_data.= $this->test_pagespeed($domain.$test_uri);
                $table_data.= "</td>";
                $table = "<table cellpadding='10' border='1' style='width:100%'><tr>".$table_headers."</tr><tr>".$table_data."</tr></table>";
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
        private function validate_html($test_uri){
            $messages = "<ul>";
            $result = $this->curl('https://validator.w3.org/check?output=json&uri='.$test_uri);
            if(preg_match("#(\{.*\})#is", $result, $matches)){//remove headers this way since CURLOPT_HEADER fails!
                $json = preg_replace("#(\: \,)#is", ": \"\",", $matches[0]); // fix missing values!%@#&^#
                $result_array = json_decode($json, true);
                foreach($result_array["messages"] as $key => $message){
                    //only show errors if an actual message is present!
                    $messages.= (!empty($message["message"])? "<li style='color: red'>".$message["message"].(!empty($message['extract'])? ": <i>".htmlspecialchars($message['extract']) : "")."</i></li>" : "");
                }
            }else{
                $messages.="<li style='color: red'>Couldn't validate...</li>";
            }
            $messages.= "</ul>";
            return $messages;
        }

        //Used by this->validate_html() & this->get_dom_obj() && this->test_pagespeed() !!!
        private function curl($url){
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_HEADER, FALSE); //still returning header?
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
            //Expressionengine
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl, CURLOPT_VERBOSE, 1);
            curl_setopt($curl, CURLOPT_USERAGENT,'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.52 Safari/537.17');
            //Pass the KEES tm cookiewall
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Cookie: cookiewall=accept"));
            $return_data = curl_exec($curl);
            // Check for errors and display the error message
            if($errno = curl_errno($curl)) {
                $error_message = curl_strerror($errno);
                echo "cURL error ({$errno}):\n {$error_message}";
            }
            curl_close($curl);
            return $return_data;
        }

        //Get DOM object & HTML source!
        private function get_dom_obj($test_uri, &$html_source){
            //Is this URL available?
            if(strstr(@get_headers($test_uri)[0], "200") === false && "dev" != ENV){ //does not work on DEV!
                return false;
            }else{
                $html_source = $this->curl($test_uri);
                libxml_use_internal_errors(true);
                $dom_obj = new DOMDocument();
                $dom_obj->loadHTML($html_source);
                return $dom_obj;
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
        $messages.= $this->check_detectify_measures($dom_obj);
        //Find stray (non inline javascript) twig/EE tags
        $messages.= $this->find_stray_tags($html_source);
        $messages.= "</ul>";
        return $messages;
    }

    private function check_structure_headings($dom_obj){
        $heading_requiring_elements = Array("heading", "section", "article", "nav");
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
                        $found_element["selector"] = ($child_node->getAttribute("id")? "id=".$child_node->getAttribute("id"):($child_node->getAttribute("class")? "class=".$child_node->getAttribute("class") : "none"));
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
                    $list.= $node["element"]." <i>".$node["selector"]."</i>";
                    $list.= "<ul>";
                    if(isset($node["sub_structure"]["headings"])){
                        $list.= $this->show_structure($node);
                    }elseif(isset($node["sub_structure"][0]["headings"])){
                        $list.= $this->show_structure($node["sub_structure"]);
                    }
                    $list.= (isset($node["sub_structure"])? $this->show_structure($node["sub_structure"]) : "");
                    $list.= "</ul>";
                    $list.= "</li>";
                }elseif(isset($node["headings"])){
                    foreach($node["headings"] as $heading){
                        $list.= "<li style='color:green'>".$heading["selector"]." <i>".($heading["value"]? $heading["value"] : "-empty-")."</i></li>";
                    }
                    $list.= $this->show_structure($node);
                }else{
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
        $active_state_message = "";
        foreach($dom_obj->getElementsByTagName('header') as $header){
            $active_state_message = $this->find_active_state($header);
            if(empty($active_state_message)){
                $messages = "<li style='color:red'><strong>NO</strong> main navigation active states found on a or listing tags</li>";
            }else{
                $messages = "<li style='color:green'>Main navigation ".$active_state_message."</li>";
            }
        }
        //subnav present?
        $active_state_message = "";
        foreach($dom_obj->getElementsByTagName('ul') as $ul){
            if(strstr($ul->getAttribute("class"), "nav")){ //this is a subnav!
                $active_state_message = $this->find_active_state($ul);
                if(!empty($active_state_message)){ break; }
            }
        }
        if(!empty($active_state_message)){ //subnav is not required!
            $messages.= "<li style='color:green'>SUB navigation ".$active_state_message."</li>";
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
                        if(strstr(@get_headers($domain.$href)[0], "200")){ //does not work on DEV!
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
                        //Check if it's a  local or remote (or even protocol relative!) file URL so we can see if the file actually exists
                        $file_url = (strstr($href, "http")? "": (preg_match("#^\/\/#", $href)? "https:": $domain)).$href;
                        if(strstr(@get_headers($file_url)[0], "200")){ //does not work on DEV!
                            $stylesheet_file_message = "file <i>".$href."</i> found";
                            $stylesheet_good = true;
                        }else{
                            $stylesheet_file_message = "file <i>".$href."</i> NOT found";
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
            $messages.= "<li style='color:".($stylesheets_good?"green":"red")."'>Stylesheet(s):<ul>".$style_sheets."</ul></li>";
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
            if($meta_tag_message){
                $meta_tag_messages.= "<li style='color: ".($good? "green":"red")."'>".$meta_tag_message.$meta_content_message."</li>";
            }
        }
        if(!$meta_description_found){
            $meta_tag_messages.= "<li style='color:red'><strong>Meta description</strong> tag NOT present</li>";
            $good = false;
        }
        if(!$meta_og_found){
            $meta_tag_messages.= "<li style='color:red'><strong>Meta OG</strong> tag NOT present</li>";
            $good = false;
        }
        if(!$meta_app_found){
            $meta_tag_messages.= "<li style='color:red'><strong>Meta msapplication</strong> tag NOT present</li>";
            $good = false;
        }
        $messages.= "<li style='color:".($good?"green":"red")."'>Meta-tags:<ul>".$meta_tag_messages."</ul></li>";
        return $messages;
    }

    //Used by meta-description & -msapplication & -OG tags in this->check_meta_tags()
    private function get_meta_content($meta_tag, &$meta_content_message, &$good){
        $content = $meta_tag->getAttribute("content");
        if(!empty($content)){
            $meta_content_message = "<i>content=".$content."</i>";
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
                //Check if it's a  local or remote (or even protocol relative!) file URL so we can see if the file actually exists
                $file_url = (strstr($src, "http")? "": (preg_match("#^\/\/#", $src)? "https:": $domain)).$src;
                if(strstr(@get_headers($file_url)[0], "200")){ //does not work on DEV!
                    $script_file_message = " file <i>".$src."</i> found";
                    $color = "green";
                }else{
                    $script_file_message = " file <i>".$src."</i> NOT found</li>";
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
                //Check if it's a  local or remote (or even protocol relative!) file URL so we can see if the file actually exists
                $file_url = (strstr($src, "http")? "": (preg_match("#^\/\/#", $src)? "https:": $domain)).$src;
                if(strstr(@get_headers($file_url)[0], "200")){ //does not work on DEV!
                    $good = true;
                    $src_message = " file <i>".$src."</i> found,";
                }else{
                    $good = false;
                    $images_good = false;
                    $src_message = " file <i>".$src."</i> NOT found,";
                }
            }else{
                $src_message = " <strong>Src attribute</strong> EMPTY or NOT found!";
                $good = false;
                $images_good = false;
            }
            $alt = $img_tag->getAttribute("alt");
            if(!empty($alt)){
                $good = ($good? true : false); //we cannot go back to good if it's already bad!
                $alt_message = " alt <i>".$alt."</i> found,";
            }else{
                $alt_message = " <strong>Alt attribute</strong> EMPTY or NOT found!";
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

    private function check_detectify_measures($dom_obj){
        $messages = "";
        //check if mailto links have an attribute rel=noopener
        foreach($dom_obj->getElementsByTagName("a") as $a){
            $href = $a->getAttribute("href");
            //What do we call this link?
            $name = $a->textContent;
            $class = $a->getAttribute("class");
            if(empty($content) && !empty($class)){
                $name = $class;
            }
            $id = $a->getAttribute("id");
            if(empty($content) && !empty($id)){
                $name = $id;
            }
            if(!empty($href)){
                if(preg_match("#^mailto\:#", $href)){
                    $rel = $a->getAttribute("rel");
                    if(!empty($rel)){
                        $messages.= "<li style='color:green'>Mailto-link <strong>".$name."</strong> to <i>".$href." has a 'noopener' rel-attribute (Detectify)</li>";
                    }else{
                        $messages.= "<li style='color:red'>Mailto-link <strong>".$name."</strong> to <i>".$href." <strong>has no 'noopener' rel-attribute</strong> (Detectify)</li>";
                    }
                }else{
                    //This is just too much!
                    //$messages.= "<li style='color:green'>Link <strong>".$name."</strong> to <i>".$href."</i> found</li>";
                }
            }else{
                $messages.= "<li style='color:red'>Link <strong>".$name."</strong> <strong>has no href attribute</strong></li>";
            }
        }
        if($messages){
            return "<li style='color:red'>Links:<ul>".$messages."</ul></li>";
        }
    }

    //Find mustache tags, excluding those inside inline script tagpairs, which could be unparsed twig or EE tags!
    private function find_stray_tags($dom_obj){
        $messages = "";
        preg_match_all("#(<script(?:[^>]*)>)\{.{3,50}\}(</script>)#is", $dom_obj, $matches);//32 is Drupal field maxlength, ignore script-tags!
        foreach($matches[0] as $match){
            $messages.= "<li style='color:red'>stray tag <i>".$match."</i> found</li>";
        }
        return $messages;
    }

    private function test_pagespeed($page_url){
        $feedback = "<h2>Pagespeed test</h2>";
        $feedback.= "<ul>";
        $strategies = Array("mobile", "desktop");
        foreach($strategies as $strategy){
            $result = $this->curl("https://www.googleapis.com/pagespeedonline/v4/runPagespeed?url=".$page_url."&strategy=".$strategy);
            $pagespeed_array = json_decode($result, true);
            if(!isset($pagespeed_array["error"])){
                $score = $pagespeed_array["ruleGroups"]["SPEED"]["score"];
                $color = ($score>75? "green" : ($score > 50? "orange" : "red" ));
                $feedback.= "<li style='color:".$color."'><h3>".ucfirst($strategy)." score: ".$score."</h3></li>";
                if(empty($feedback_summary)){
                    foreach($pagespeed_array["formattedResults"]["ruleResults"] as $key => $rule_result){
                        $extra_info = "";
                        $summary = $rule_result["summary"]["format"];
                        if(isset($rule_result["summary"]["args"][0]["value"]) && "LINK" == $rule_result["summary"]["args"][0]["key"]){
                            $link = $rule_result["summary"]["args"][0]["value"];
                            $summary = str_replace(Array("{{BEGIN_LINK}}", "{{END_LINK}}"), Array("<a href='".$link."' target='_blank'>", "</a>"), $summary);
                        }
                        if(isset($rule_result["summary"]["args"][0]["value"]) && "RESPONSE_TIME" == $rule_result["summary"]["args"][0]["key"]){
                            $response_time = $rule_result["summary"]["args"][0]["value"];
                            $summary = str_replace(Array("{{RESPONSE_TIME}}"), Array($response_time), $summary);
                        }
                        if(isset($rule_result["summary"]["args"][0]["value"]) && "NUM_CSS" == $rule_result["summary"]["args"][0]["key"]){
                            $num_css = $rule_result["summary"]["args"][0]["value"];
                            $summary = str_replace(Array("{{NUM_CSS}}"), Array($num_css), $summary);
                            if(isset($rule_result["urlBlocks"][1]["urls"])){
                                foreach($rule_result["urlBlocks"][1]["urls"] as $url){
                                    if(isset($url["result"]["args"][0]["value"])){
                                        $extra_info.= "<li style='color:".$color."'>".$url["result"]["args"][0]["value"]."</li>";
                                    }
                                }
                            }
                        }
                        $feedback.= "<li style='color:".$color."'>".$rule_result["localizedRuleName"].": ".$summary.(!empty($extra_info)? "<ul>".$extra_info."</ul>": "")."</li>";
                    }
                }
            }else{
                $feedback.= "<li style='color:red'>".$pagespeed_array["error"]["message"]."</li>";
            }
        }
        $feedback.= "</ul>";
        return $feedback;
    }

}
