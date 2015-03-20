<?php
namespace Icicle\Dns\Executor\Exception;

use Icicle\Dns\Query\QueryInterface;

class NotFoundException extends RuntimeException
{
    private $query;
    
    public function __construct(QueryInterface $query)
    {
        parent::__construct("Could not find {$query->getTypeName()} record for {$query->getName()}.");
        
        $this->query = $query;
    }
    
    public function getQuery()
    {
        return $this->query;
    }
}
