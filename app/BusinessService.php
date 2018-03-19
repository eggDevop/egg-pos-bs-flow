<?php
namespace Eggdigital;

class BusinessService {

    private $config;
    private $classes       = [];
    private $stacks        = [];
    private $responses     = [];
    private $breakProcess  = '';
    private $processFail   = [];


    public function __construct($configs) {
        $this->configs = $configs;
    } 

    //========== Start: Private Zone ==========//
    //Method for get service name from process
    protected function getServiceName($processes)
    {
        $name = $processes['service'];

        if (isset($processes['alias']) && !empty($processes['alias'])) {
            $name = $processes['alias'];
        }

        return $name;
    }

    //Method for manage success
    protected function manageSuccess($processes, $params, $datas)
    {
        $each = [
            'processes' => $processes,
            'params'    => $params,
            'response'  => $datas,
        ];
        //keep response data to stack
        $this->stacks[] = $each;

        //add data to response
        $this->addResponsedata($this->getServiceName($processes), $datas);

        return $each;
    }

    //Method for manage fail
    protected function manageFail($service, $process, $response)
    {
        //add service name to process fail
        $this->processFail[] = $service;
        $this->addResponsedata($service, $response);
        switch ($process) {
            case 'rollback':
                $this->rollback();
                $this->breakProcess = $service; //after rollback should break process 
                break;
            case 'break':
                $this->breakProcess = $service;
                break;
        }
    }

    //Method for get rollback class
    protected function getRollbackClass($processes)
    {
        if (strrpos($processes['rollback'], '.') !== FALSE) {
            return explode('.', $processes['rollback']);
        }

        return [$processes['service'], $processes['rollback']];
    }

    //Method for get rollback data
    protected function getRollbackData($last, &$processes)
    {
        $last = null;
        if (is_array($processes['rollback'])) {
            $method = $processes['rollback']['method'];
            $data   = $processes['rollback']['data'];

            //update $processes
            $processes['rollback'] = $method;

            $pathData = explode('.', $processes['rollback']['data']);
            $lastResponse = $this->getResponse($pathData[0]);

            foreach (array_slice($pathData, 1) as $key) {;
                $last = $last[$key];
            }

            return $last;
        }

        return $last['response']['data'];
    }

    //Method for manage rollback
    protected function rollback()
    {
        if (empty($this->stacks)) {
            return true;
        } 

        //get last stacks
        $last = array_pop($this->stacks);
        //rollback
        //get processes
        $processes = $last['processes'];

        if (isset($processes['rollback'])) {

            //get data
            $rollbackData             = $this->getRollbackData($last, $processes);
            
            //get class
            list($service, $rollback) = $this->getRollbackClass($processes);

            $class = $this->getClass($service);
            
            $res   = $this->requestMethod($class, $rollback, $rollbackData);

            $this->addResponsedata($this->getServiceName($processes).'_rollback', $res);
        }

        if (!empty($this->stacks)) {
            return $this->rollback();
        } 

        return true;
    }

    //Method for get value from object
    protected function getValueFormObj($keys, $obj)
    {
        $key  = $keys[0];

        //keep in $obj
        if (isset($obj[$key])) {
            $obj  = $obj[$key];

            if (count($keys) > 1) {
                //cut first
                $keys = array_slice($keys, 1);
                return $this->getValueFormObj($keys, $obj);
            }
        } else {
            return "";
        }

        return $obj;

    }

    //Method for call method in class
    protected function requestMethod($class, $method, $params)
    {
        return $class->{$method}($params);
    }

    //Method for add respose data 
    protected function addResponsedata($service, $respose)
    {
        $this->responses[$service] = $respose;
    }

    //method for format param
    protected function formatParams($params, $formats)
    {
        $outputs = $params;
        foreach ($formats as $key => $newKey) {
            if (isset($params[$key])) {
                $outputs[$newKey] = $params[$key];
                continue;
            }

            $resVal = $this->getValueFormObj(explode('.', $key), $this->responses);
            $outputs[$newKey] = $resVal;
        }

        return $outputs;
    }
    //========== End: Private Zone ==========//

    //========== Start: Public Zone ==========//
    //Method for set class
    public function setClass($name, $class) 
    {
        $this->classes[$name] = $class;
    }

    //Method for get class
    public function getClass($name)
    {
        return $this->classes[$name];
    }

    //Method for get response
    public function getResponse($key='')
    {
        if (isset($this->responses[$key])) {
            return $this->responses[$key];
        }

        return $this->responses;
        
    }

    //get help
    public function help($service=null, $method="")
    {
        if (empty($service)) {
            return [];
        } 

        $class = $this->getClass($service);

        if (isset($class->helps[$method])) {
            return $class->helps[$method];
        }
        
        return $class->helps['method_list'];
        
    }

    //Method for run service
    // processes is object
    //EX : [
    //          "main"     => "methodName",
    //          "fail"     => "rollback",
    //          "rollback" => "methodName",
    //          "service"  => "className",
    //          "alias".   => "servicename"
    //          "format"   => ["a" => "x"],
    //     ]
    public function runServices($params, $processes)
    {
        if (!empty($this->breakProcess)) {
            return $this;
        }
        $service = $processes['service'];

        //format params
        $params  = $this->formatParams($params, $processes['format']);

        //get class
        $class   = $this->getClass($service);
        
        $res     = $this->requestMethod($class, $processes['main'], $params);

        if ($res['success']) {
            //Success
            $this->manageSuccess($processes, $params, $res);
        } else {
            //Fail
            if (isset($processes['fail']) && ($processes['fail'] !== 'ignore')) {
                $this->manageFail($this->getServiceName($processes), $processes['fail'], $res);
            }
            
        }

        return $this;

    } 

    //method for get all processes status
    public function isSuccess() 
    {
        if (empty($this->processFail)) {
            return true;
        }

        return false;
    }

    //method for get sevice fail
    public function getServiceFail()
    {
        return $this->processFail;
    }

    //Method for get response by service list
    public function getResponseList($services)
    {
        $responses = [];

        foreach ($services as $service) {
            $responses[$service] = $this->getResponse($service);
        }
        return $responses;
    }
    //========== End: Public Zone ==========//

}