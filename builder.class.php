<?php
if(!defined(MODX_CORE_PATH))define(MODX_CORE_PATH,  __DIR__.'/modx/core/');
require_once MODX_CORE_PATH.'model/modx/modx.class.php';

class modX_idle extends modX {
    protected $systemOptions=[
        'core_path'=>MODX_CORE_PATH,
        'log_target'=>'ECHO'
    ];
    protected function loadConfig($configPath = '', $data = array(), $driverOptions = null) {
        return [
            xPDO::OPT_CACHE_KEY => 'default',
            xPDO::OPT_CACHE_HANDLER => 'xPDOFileCache',
            xPDO::OPT_CACHE_PATH => MODX_CORE_PATH . 'cache/',
            xPDO::OPT_HYDRATE_FIELDS => true,
            xPDO::OPT_HYDRATE_RELATED_OBJECTS => true,
            xPDO::OPT_HYDRATE_ADHOC_FIELDS => true,
            xPDO::OPT_CONNECTIONS => [
                [
                    'dsn' => 'mysql:host=localhost;dbname=xpdotest;charset=utf8',
                    'username' => 'test',
                    'password' => 'test',
                    'options' => [xPDO::OPT_CONN_MUTABLE => true],
                    'driverOptions' => [],
                ]
            ]
        ];
    }
    protected function _loadConfig() {
        $this->config=[
            'dbtype'=>'mysql',
            xPDO::OPT_CACHE_PATH => MODX_CORE_PATH . 'cache/',
            xPDO::OPT_CACHE_FORMAT=>xPDOCacheManager::CACHE_PHP
        ];
        $this->_systemConfig = $this->config;
        return true;
    }
    public function getObject($className, $criteria= null, $cacheFlag= true){
        switch(true){
            case $className=='modWorkspace':{
                return $this->newObject('modWorkspace',[
                    'path'=>MODX_CORE_PATH
                ]);
            }
            case $className=='modNamespace':{
                return false;
            }
            default:
                return parent::getObject($className, $criteria, $cacheFlag);
        }
    }
    public function getOption($key, $options = null, $default = null, $skipEmpty = false){
        if($options==null){
            $options=$this->systemOptions;
        }
        return parent::getOption($key, $options , $default, $skipEmpty);
    }
}


class packageBuilder{
    public $modx=null;
    public $config=[];
    public $builder=null;
    public $data=[];
    public $objects=[];
    public $vehicles=[];
    
    public function __construct(&$modx,$config){
        $this->modx=$modx;
        $this->config=$config;
        
        $this->modx->loadClass('transport.modPackageBuilder','',false, true);
    }
    
    public function build(){
        $this->builder = new modPackageBuilder($this->modx);
        $this->builder->createPackage($this->config['component']['namespace'],$this->config['component']['version'],$this->config['component']['release']);
        $this->builder->registerNamespace(
            $this->config['component']['namespace'],false,true,
            '{core_path}components/'.$this->config['component']['namespace'].'/',
            '{assets_path}components/'.$this->config['component']['namespace'].'/'
        );
        
        $this->addResolvers($this->config['component']['resolvers']['before']);
        
        $this->data=$this->collectObjectsData();
        $keys=$this->createObjects($this->data);
        $this->addVehicles($this->data,$keys);
        
        $this->addResolvers($this->config['component']['resolvers']['after']);
        
        
        $this->setAttributes($this->config['component']['attributes']);
        
        $this->builder->pack();
    }
    
    public function addResolvers($resolvers){
        $this->vehicles[]=$this->builder->createVehicle(
            ['source' => $this->config['vehicles'].'resolvers.vehicle.php',],
            $this->addComponentInfo(['vehicle_class'=>'xPDOScriptVehicle'])
        );
        $vehicle=&$this->vehicles[count($this->vehicles)-1];
        foreach($resolvers as $resolver){
            $vehicle->resolve($resolver['type'],$resolver['options']);
        }
        $this->builder->putVehicle($vehicle);
    }
    
    public function addComponentInfo($options=array()){
        return array_merge(['component'=>[
            'name' => $this->config['component']['name'],
            'namespace' => $this->config['component']['namespace'],
        ]],$options);
    }
    
    public function setAttributes($attributes){
        $this->builder->setPackageAttributes($attributes);
    }
    
    public function addVehicles($data,$keys){
        foreach($keys as $i=>$key){
            $this->vehicles[] = $this->builder->createVehicle(
                $this->objects[$key]['object'],
                $this->addComponentInfo($this->objects[$key]['attrs'])
            );
            $vehicle=&$this->vehicles[count($this->vehicles)-1];
            list($class,$name)=explode('@',$key);
            $resolvers=array_values($data[$class][$name]['resolvers']?:[]);
            //if($i==0)$resolvers=array_merge(array_values($this->config['component']['resolvers']?:[]),$resolvers);
            foreach($resolvers as $resolver){
                $vehicle->resolve($resolver['type'],$resolver['options']);
            }
            $this->builder->putVehicle($vehicle);
        }
    }

    public function collectObjectsData(){
        //Собираем карту объектов из data
        $data=[];
        $config=$this->config;
        $modx=$this->modx;
        $iterator = new IteratorIterator(new DirectoryIterator($this->config['data']));
        $iterator->rewind();
        while($iterator->valid()){
        	if($iterator->isDir()){$iterator->next();continue;}
        	$vars = array_keys(get_defined_vars());
        	include_once($iterator->getPathname());
        	$vars = array_diff(array_keys(get_defined_vars()),$vars);
            foreach($vars as $varkey){
                unset(${$varkey});
            }
        	$iterator->next();
        }
        return $data;
    }
    public function createObjects($data){
        //Создаём объекты
        $keys=[];
        foreach($data as $class=>$objects){
            foreach($objects as $oname=>$properties){
                $k=$this->addObject($class,$oname,$data);
                if($k&&!$properties['relations'])$keys[]=$k;
            }
        }
        /*echo '======================================================';
        foreach($vehicles_objects as $key){
            var_dump($this->objects[$key]['object']->toArray('',false,false,true));
            var_dump($this->objects[$key]['attrs']);
        }*/
        return $keys;
    }
    
    public function addObject($class,$oname,&$data){
        $k=$class.'@'.$oname;
        if(isset($this->objects[$k]))return $k;
        
        $properties=$data[$class][$oname];
        if(empty($properties['options']['search_by'])){
            $this->modx->log(MODX_LOG_LEVEL_ERROR,'You must declare "search_by" option for '.$k);
            return false;
        }
        
        $storage=&$this->objects[$k];
        $storage['object']=$this->modx->newObject($class);
        
        $pk=$this->modx->getPK($class);
        if(!is_array($pk))$pk=[$pk];
        if(in_array('id',$pk))$properties['fields']['id']=0;
        
        $storage['object']->fromArray($properties['fields'],'',true,true);
        $storage['attrs']=array(
            xPDOTransport::UNIQUE_KEY => $properties['options']['search_by'],
            xPDOTransport::UPDATE_OBJECT => $properties['options']['update']??true,
            xPDOTransport::PRESERVE_KEYS => $properties['options']['preserve']??false,
        );
        if($properties['relations']){
            foreach($properties['relations'] as $rclass=>$relations){
                $this->modx->loadClass($rclass);
                $rmap=$this->modx->map[$rclass];
                foreach($relations as $rname=>$alias){
                    $rk=$this->addObject($rclass,$rname,$data);
                    if(!$rk)continue;
                    $rstorage=&$this->objects[$rk];
                    $cardinality=$rmap['composites'][$alias]['cardinality']?:$rmap['aggregates'][$alias]['cardinality'];
                    if($cardinality){
                        $rstorage['object']->{'add'.strtoupper($cardinality)}($storage['object'],$alias);
                        $rstorage['attrs'][xPDOTransport::RELATED_OBJECTS]=true;
                        $rstorage['attrs'][xPDOTransport::RELATED_OBJECT_ATTRIBUTES][$alias]=$storage['attrs'];
                        $storage['attrs']=&$rstorage['attrs'][xPDOTransport::RELATED_OBJECT_ATTRIBUTES][$alias];
                    }
                }
            }
        }
        
        return $k;
    }
}