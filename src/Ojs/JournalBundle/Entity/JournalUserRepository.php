<?php

namespace Ojs\JournalBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * JournalUserRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class JournalUserRepository extends EntityRepository
{
    public function findByRoles($roles, $journal = null)
    {
        $query = $this->createQueryBuilder('journal_user')
            ->andWhere('journal_user.roles IN (:roles)')
            ->setParameter('roles', $roles);

        if ($journal) {
            $query
                ->andWhere('journal_user.journal = :journal')
                ->setParameter('journal', $journal);
        }

        return $query;
    }
}