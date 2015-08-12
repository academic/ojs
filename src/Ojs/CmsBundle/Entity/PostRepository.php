<?php

namespace Ojs\CmsBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * PostRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class PostRepository extends EntityRepository
{
    public function getByType($type, $object, $id)
    {
        return $this->findBy(['post_type' => $type, 'object' => $object, 'objectId' => $id, 'status' => 1]);
    }

    public function getByObject($object, $id)
    {

        $data = $this->findBy(['object' => $object, 'id' => $id, 'status' => 1]);
        return $data;
    }
}