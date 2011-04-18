<?php
/* 
    Used to load an external class definition. 
    Ditched this approach to make it simple to package the snippet using the PacMan addon.
  
    Below the class definition you find the actual snippet code...
  
  */
  
  //require_once($modx->config['base_path']."assets/snippets/simplx/simplx_widgeteer.php"); 
  
if(!class_exists('simplx_widgeteer')){
      
    class simplx_widgeteer{
          
          public $dataSet;      
          public $dataSetUrl;
          public $dataSetArray;
          public $dataSetRoot;
          public $useChunkMatching = true;
          public $chunkMatchingSelector = '';    
          public $staticChunkName = '';    
          public $chunkPrefix = '';    
          public $chunkMatchRoot = false;    
          public $preprocessor = '';
          private $iterator = 0;
          private $traversalStack = array();
          private $traversalObjectStack = array();
          private $traversalContext = '';
      
          public function preprocess(&$dSet){
            global $modx;   
            if ($this->preprocessor) {
              $dSet = $modx->runSnippet($this->preprocessor,array('dataSet'=>$dSet));                
            }            
             return $dSet; 
          } 

          public function loadDataSet($dSet){ 
            $dSet = $this->preprocess($dSet);
            $this->dataSet = $dSet;
            $this->dataSetArray = $this->decode($dSet);
            
            //$this->cacheDataSet($dSet);

          }

          public function loadCachedDataSet($dataSetKey){ 
            $dSet = $modx->cacheManager->get($dataSetKey);
            if ($dSet) {
              return $dSet;
            }else{
              return '';
            }
                        
          }
      
          public function cacheDataSet($dSet){ 
            global $modx;
            // For now we just cache remote dataSets...
            if ($this->dataSetUrl != '') {
              $dataSetLocator = urlencode($this->dataSetUrl);
              $modx->cacheManager->set(('dataSet.'.$dataSetLocator),$this->dataSetArray);          
            }
          }
      
          public function loadDataSource($dSourceURL){
              if ($dSourceURL != "") {

                  $ch = curl_init();
                  curl_setopt($ch, CURLOPT_URL, $dSourceURL);
                  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      
                  $output = curl_exec($ch);
                  curl_close($ch);  

                  $this->dataSetUrl = $dSourceURL;
                
                  if ($output != "") {                   
                    $this->loadDataSet($output);      
                  }
              }
          }
      
          public function setDataRoot($elName){
              $this->dataSetRoot = $elName;
              $this->dataSetArray = $this->dataSetArray[$this->dataSetRoot];
              
          }
      
          public function decode($json){
              return json_decode($json,true);
      
          }
          
          public function encode($object){
              return json_encode($object);        
          
          }
          

          function parse(&$obj){
            global $modx; 
            $result = '';
            
            if(!isset($obj)){
              $obj = &$this->dataSetArray;
            }
            if($this->dataSetRoot != ''){
              $context = $this->dataSetRoot;
            }                        
            foreach ($obj as  $key => &$val) {

              switch($this->typeCheck(&$val)){
                case "list":
                  $result = $this->parseList(&$val,$key);
                  break;
                case "object":
                  $result = $this->parseObject(&$val,$key);                
                  break;
                case "simple":
                  $result = $this->parseSimpleType(&$val,$key);
                  break;
                default :
                  break;                                    
              }
              $obj[$key] = $result;
                
            }

            $result = implode(' ',&$obj);
            
            
            // If this is the last render call we have the option to wrap the result in the 
            // rootChunk.
            
            if($this->chunkMatchRoot == 'true'){        
              $rootChunk = $modx->getChunk(($this->chunkPrefix.$this->dataSetRoot));
              if($rootChunk != ''){
                $result = str_replace('[[+content]]',$result,$rootChunk);    
              }else{
              
              }
            }             
            
            return $result;  
          }
                     
          function parseObject(&$obj,$context){
            $result = '';
            
            foreach ($obj as  $key => &$val) {
              
              switch($this->typeCheck(&$val)){
                case 'list':
                  $result = $this->parseList(&$val,$key);
                  break;
                case 'object':
                  $result = $this->parseObject(&$val,$key);     
                  break;
                case 'simple':
                  $result = $this->parseSimpleType(&$val,$key);
                    
                  break;
                default:
                  break;                                    
              }
              $obj[$key] = $result;
            }

            $result = $this->template(&$obj,$context);

            return $result;           
          }

          function parseList(&$list,&$context){
            $result = '';
            $iterator = 0;

            foreach ($list as &$index) {
                
              switch($this->typeCheck(&$index)){
                case 'list':
                  $result = $this->parseList(&$index,$iterator);
                  break;
                case 'object':                  
                  $result = $this->parseObject(&$index,$context);                
                  break;
                case 'simple':
                  $result = $this->parseSimpleType(&$index,$iterator);
                  break;
                default:
                  break;                                    
              }
              $list[$iterator] = $result;                          
              $iterator++;
            }
            $result = implode(' ',&$list);
            return $result; 
          }

          function parseSimpleType(&$type,$context){
            return $type;
          }
          
          function typeCheck(&$var){
            $val = '';
            if(is_array($var)){
              if ($this->is_assoc(&$var)) {
                $val = 'object';
                
              }else{
                $val = 'list';
              }
            }else{
              $val = 'simple';
            }       
            return $val;  
          }
          
          function template(&$collection,$tmplName) {
            global $modx; 
            $res;
            $tempVar;
            if ($this->useChunkMatching) {                   

              // Get the current chunkMatchingSelector key from the $collection list.
              // This is used later to choose which Chunk to use as template.             
              $chunkName = $collection[$this->chunkMatchingSelector];

              if ($chunkName == '') {
              /*
                If nothing was returned from the assignment above we have found no selector. We have to
                use another way to match the current collection in the $collection list.
                The way that json is structured it is very likely that the parent key is the name of the 
                object type in the collection. 
                Example 
              
                  {
                    "contact":[
                      {
                        "name":"Mini Me",
                        "shoesize":{"eu":"23"} 
                      },
                      {
                        "name":"Big Dude",
                        "shoesize":{"eu":"49"} 
                      }        
              
                    ]                
                  }
              
                In the example above its implied that each item in the "contact" collection is of typ... contact :)
                Similarly the "shoesize" property is a complex value that would be best matched to the key "shoesize".

              */                 
                $chunkName = $tmplName;
              }else{
              }
              
              if($chunkName != ''){        
                $tempVar = $modx->parseChunk(($this->chunkPrefix.''.$chunkName), $collection, '[[+', ']]');
                $res .= $tempVar;
              }
            }else{
               $tempVar .= $modx->parseChunk($this->staticChunkName, $collection, '[[+', ']]');
                $res .= $tempVar;
            }    
            if(!$res){
              $res = '';
            }else{}
            return $res;
          }

        // Utility function to check type of Array  
        function is_assoc (&$arr) {
            try{
              return (is_array($arr) && (!count($arr) || count(array_filter(array_keys($arr),'is_string')) == count($arr)));
            }catch(Exception $e){
              return false;
              }
          }      
      } 

  }else{

  }        

  /*

    ----------------------------------------------------------------------------------------------
      
    Below is the actual snippet code which sets defaults, validates the input, instatiates the 
    Widgeteer object and runs the logic...
  
  */
      
  $dataSourceUrl = isset($dataSourceUrl) ? $dataSourceUrl : 'null';
  $dataSourceUrl = isset($dataSetUrl) ? $dataSetUrl : $dataSourceUrl; //New interface parameter to mend naming consistency issue.      
  $staticChunkName = isset($staticChunkName) ? $staticChunkName : 'null';
  $dataSet = isset($dataSet) ? ($dataSet) : 'null';
  $useChunkMatching = isset($useChunkMatching) ? $useChunkMatching : true;
  $chunkMatchingSelector = isset($chunkMatchingSelector) ? $chunkMatchingSelector : 'objecttypename';
  $dataSetRoot = isset($dataSetRoot) ? $dataSetRoot : 'null';
  $chunkMatchRoot = isset($chunkMatchRoot) ? $chunkMatchRoot : false;
  $chunkPrefix = isset($chunkPrefix) ? $chunkPrefix : '';
  
  $dataSet = str_replace(array('|xq|','|xe|','|xa|'),array('?','=','&') , $dataSet);
  
  $preprocessor = isset($preprocessor) ? $preprocessor : '';   
      
  if($dataSourceUrl == 'null' && $dataSet == 'null'){
      print '{"result":[{"objecttypename":"exception","errorcode"="0","message":"The dataSet parameter is empty."}]}';
  }else{
   
      $w = new simplx_widgeteer();
    
    /* 
      PHP bug perhaps? $useChunkMatching evaluates as true even if its false!? 
      I have to "switch poles" in order to get the right effect...
    */ 
      if($useChunkMatching && $staticChunkName != 'null'){

        $w->useChunkMatching = false;
        $w->staticChunkName = $staticChunkName;  
        
      }else{
        $w->useChunkMatching = true;
        $w->chunkMatchingSelector = $chunkMatchingSelector;     
      }        
      
      $w->chunkMatchRoot = $chunkMatchRoot;
      $w->chunkPrefix = $chunkPrefix;   
      $w->preprocessor = $preprocessor;   
    
      if($dataSourceUrl != 'null'){    
          $dataSourceUrl = urldecode($dataSourceUrl);
          $w->loadDataSource($dataSourceUrl);
          
      }else{
          
          $w->loadDataSet(utf8_encode($dataSet));
      }
      
      if($dataSetRoot != 'null'){
          $w->setDataRoot($dataSetRoot);
      }
      
      print $w->parse(); 
  }​