<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\Entity;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * LeadRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class LeadRepository extends CommonRepository
{

    /**
     * {@inheritdoc}
     *
     * @param $entity
     * @param $flush
     * @return int
     */
    public function saveEntity($entity, $flush = true)
    {
        $this->_em->persist($entity);

        $fieldValues = $entity->getFieldValues();
        foreach ($fieldValues as $field) {
            $this->_em->persist($field);
        }

        if ($flush)
            $this->_em->flush();
    }

    /**
     * {@inheritdoc}
     *
     * @param int $id
     * @return mixed|null
     */
    public function getEntity($id = 0)
    {
        try {
            $entity = $this
                ->createQueryBuilder('l')
                ->select('l, f, v, u, i')
                ->leftJoin('l.fields', 'v')
                ->leftJoin('v.field', 'f')
                ->leftJoin('l.ipAddresses', 'i')
                ->leftJoin('l.owner', 'u')
                ->where('l.id = :leadId')
                ->setParameter('leadId', $id)
                ->getQuery()
                ->getSingleResult();
        } catch (\Exception $e) {
            $entity = null;
        }
        return $entity;
    }

    /**
     * Get a list of leads
     *
     * @param array      $args
     * @param Translator $translator
     * @return Paginator
     */
    public function getEntities($args = array())
    {
        $q = $this
            ->createQueryBuilder('l')
            ->select('l, f, v, u, i')
            ->leftJoin('l.fields', 'v')
            ->leftJoin('v.field', 'f')
            ->leftJoin('l.ipAddresses', 'i')
            ->leftJoin('l.owner', 'u');

        if (!$this->buildClauses($q, $args)) {
            return array();
        }

        $query = $q->getQuery();
        $results = new Paginator($query);

        //use getIterator() here so that the first lead can be extracted without duplicating queries or looping through
        //them twice
        $iterator = $results->getIterator();

        if (!empty($args['getTotalCount'])) {
            //get the total count from paginator
            $totalItems = count($results);

            $iterator['totalCount'] = $totalItems;
        }

        return $iterator;
    }

    /**
     * @param QueryBuilder $q
     * @param              $filter
     * @return array
     */
    protected function addCatchAllWhereClause(QueryBuilder &$q, $filter)
    {
        $unique  = $this->generateRandomParameterName(); //ensure that the string has a unique parameter identifier
        $string  = ($filter->strict) ? $filter->string : "%{$filter->string}%";

        $expr = $q->expr()->like('v.value',  ':'.$unique);

        if ($filter->not) {
            $q->expr()->not($expr);
        }

        return array(
            $expr,
            array("$unique" => $string)
        );
    }

    /**
     * @param QueryBuilder $q
     * @param              $filter
     * @return array
     */
    protected function addSearchCommandWhereClause(QueryBuilder &$q, $filter)
    {
        $command         = $field = $filter->command;
        $string          = $filter->string;
        $unique          = $this->generateRandomParameterName();
        $returnParameter = true; //returning a parameter that is not used will lead to a Doctrine error
        $ignoreGlobalNot = false; //do not set negative for special circumstances
        $expr            = false;
        $parameters      = array();

        switch ($command) {
            case $this->translator->trans('mautic.core.searchcommand.is'):
                switch($string) {
                    case $this->translator->trans('mautic.lead.lead.searchcommand.isanonymous'):
                        $f = $this->generateRandomParameterName();
                        $v = $this->generateRandomParameterName();
                        $sq = $this->getEntityManager()->createQueryBuilder();
                        $subquery = $sq->select("count({$v}.id)")
                            ->from('MauticLeadBundle:LeadField', $f)
                            ->leftJoin('MauticLeadBundle:LeadFieldValue', $v,
                                Query\Expr\Join::WITH,
                                $sq->expr()->eq($f, "{$v}.field")
                            )
                            ->where(
                                $q->expr()->andX(
                                    $q->expr()->in("{$f}.alias", array('firstname', 'lastname', 'company', 'email')),
                                    $q->expr()->eq("{$v}.lead", 'l'),
                                    $q->expr()->orX(
                                        $q->expr()->eq("{$v}.value", $q->expr()->literal('')),
                                        $q->expr()->isNull("{$v}.value")
                                    )
                                )
                            )
                            ->getDql();
                        $expr = $q->expr()->eq(sprintf("(%s)",$subquery), 4);
                        break;
                    case $this->translator->trans('mautic.core.searchcommand.ismine'):
                        $expr = $q->expr()->eq("l.owner", $this->currentUser->getId());
                        break;
                    case $this->translator->trans('mautic.lead.lead.searchcommand.isunowned'):
                        $expr = $q->expr()->orX(
                            $q->expr()->eq("l.owner", 0),
                            $q->expr()->isNull("l.owner")
                        );
                        break;
                }
                $returnParameter = false;
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.email'):
                $f = $this->generateRandomParameterName();
                $v = $this->generateRandomParameterName();
                $sq = $this->getEntityManager()->createQueryBuilder();
                $valueField = $q->expr()->like("{$v}.value", ':'.$unique);
                if ($filter->not)
                    $valueField = $q->expr()->not($valueField);

                $where = $q->expr()->andX(
                    $q->expr()->eq("{$f}.alias", $q->expr()->literal('email')),
                    $q->expr()->eq("{$v}.lead", 'l')
                );
                $where->add($valueField);

                $subquery = $sq->select("count({$v}.id)")
                    ->from('MauticLeadBundle:LeadField', $f)
                    ->leftJoin('MauticLeadBundle:LeadFieldValue', $v,
                        Query\Expr\Join::WITH,
                        $sq->expr()->eq($f, "{$v}.field")
                    )
                    ->where($where)
                    ->getDql();
                $expr = $q->expr()->eq(sprintf("(%s)",$subquery), 1);
                $ignoreGlobalNot = true;
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.company'):
                $f = $this->generateRandomParameterName();
                $v = $this->generateRandomParameterName();
                $sq = $this->getEntityManager()->createQueryBuilder();
                $valueField = $q->expr()->like("{$v}.value", ':'.$unique);
                if ($filter->not)
                    $valueField = $q->expr()->not($valueField);

                $where = $q->expr()->andX(
                    $q->expr()->eq("{$f}.alias", $q->expr()->literal('company')),
                    $q->expr()->eq("{$v}.lead", 'l')
                );
                $where->add($valueField);

                $subquery = $sq->select("count({$v}.id)")
                    ->from('MauticLeadBundle:LeadField', $f)
                    ->leftJoin('MauticLeadBundle:LeadFieldValue', $v,
                        Query\Expr\Join::WITH,
                        $sq->expr()->eq($f, "{$v}.field")
                    )
                    ->where($where)
                    ->getDql();
                $expr = $q->expr()->eq(sprintf("(%s)",$subquery), 1);
                $ignoreGlobalNot = true;
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.owner'):
                $expr = $q->expr()->orX(
                    $q->expr()->like('u.firstName', ':'.$unique),
                    $q->expr()->like('u.lastName', ':'.$unique)
                );
                break;
            case $this->translator->trans('mautic.core.searchcommand.name'):
                $f = $this->generateRandomParameterName();
                $v = $this->generateRandomParameterName();
                $sq = $this->getEntityManager()->createQueryBuilder();
                $valueField = $q->expr()->like("{$v}.value", ':'.$unique);
                if ($filter->not)
                    $valueField = $q->expr()->not($valueField);

                $where = $q->expr()->andX(
                    $q->expr()->in("{$f}.alias", array('firstname', 'lastname')),
                    $q->expr()->eq("{$v}.lead", 'l')
                );
                $where->add($valueField);

                $subquery = $sq->select("count({$v}.id)")
                    ->from('MauticLeadBundle:LeadField', $f)
                    ->leftJoin('MauticLeadBundle:LeadFieldValue', $v,
                        Query\Expr\Join::WITH,
                        $sq->expr()->eq($f, "{$v}.field")
                    )
                    ->where($where)
                    ->getDql();
                $expr = $q->expr()->gte(sprintf("(%s)",$subquery), 1);
                $ignoreGlobalNot = true;
                break;
            case $this->translator->trans('mautic.lead.lead.searchcommand.list'):
                //obtain the list details
                $list = $this->_em->getRepository("MauticLeadBundle:LeadList")->findOneByAlias($string);
                if (!empty($list)) {
                    $filters     = $list->getFilters();
                    $group       = false;
                    $options     = $this->getFilterExpressionFunctions();
                    $expr        = $q->expr()->andX();
                    $useExpr     =& $expr;

                    foreach ($filters as $k => $details) {
                        if (empty($details['glue']))
                            continue;

                        $f = $this->generateRandomParameterName();
                        $v = $this->generateRandomParameterName();

                        $uniqueFilter              = $this->generateRandomParameterName();
                        $parameters[$uniqueFilter] = $details['filter'];

                        $uniqueFilter              = ":$uniqueFilter";
                        $func                      = $options[$details['operator']]['func'];
                        $field                     = (strpos($details['field'], 'field_') === 0) ?
                            str_replace("field_", "", $details['field'])
                            : $details['field'];

                        //add prefix
                        if (in_array($field, array("owner", "score"))) {
                            $field     = "l.".$field;
                            $type      = "lead";
                        } else {
                            $type      = "field";
                        }

                        //the next one will determine the group
                        $glue = (isset($filters[$k + 1])) ? $filters[$k + 1]['glue'] : $details['glue'];
                        if ($glue == "or" || $details['glue'] == 'or') {
                            //create the group if it doesn't exist
                            if ($group === false)
                                $group = $q->expr()->orX();

                            //set expression var to the grouped one
                            unset($useExpr);
                            $useExpr =& $group;
                        } else {
                            if ($group !== false) {
                                //add the group
                                $expr->add($group);
                                //reset the group
                                $group = false;
                            }

                            //reset the expression var to be used
                            unset($useExpr);
                            $useExpr =& $expr;
                        }
                        if ($type == "lead") {
                            if ($func == 'notEmpty') {
                                $useExpr->add(
                                    $q->expr()->andX(
                                        $q->expr()->isNotNull($field, $uniqueFilter),
                                        $q->expr()->neq($field, $q->expr()->literal(''))
                                    )
                                );
                            } elseif ($func == 'empty') {
                                $useExpr->add(
                                    $q->expr()->orX(
                                        $q->expr()->isNull($field, $uniqueFilter),
                                        $q->expr()->eq($field, $q->expr()->literal(''))
                                    )
                                );
                            } else {
                                $useExpr->add($q->expr()->$func($field, $uniqueFilter));
                            }
                        } else {
                            $sq = $this->getEntityManager()->createQueryBuilder();
                            if ($func == 'notEmpty') {
                                $valueField = $q->expr()->andX(
                                    $q->expr()->isNotNull("{$v}.value", $uniqueFilter),
                                    $q->expr()->neq("{$v}.value", $q->expr()->literal(''))
                                );
                            } elseif ($func == 'empty') {
                                $valueField = $q->expr()->orX(
                                    $q->expr()->isNull("{$v}.value", $uniqueFilter),
                                    $q->expr()->eq("{$v}.value", $q->expr()->literal(''))
                                );
                            } else {
                                $valueField = $q->expr()->$func("{$v}.value", $uniqueFilter);
                            }

                            if ($filter->not)
                                $valueField = $q->expr()->not($valueField);

                            $where = $q->expr()->andX(
                                $q->expr()->eq("{$f}.alias", $q->expr()->literal($field)),
                                $q->expr()->eq("{$v}.lead", 'l')
                            );
                            $where->add($valueField);

                            $subquery = $sq->select("count({$v}.id)")
                                ->from('MauticLeadBundle:LeadField', $f)
                                ->leftJoin('MauticLeadBundle:LeadFieldValue', $v,
                                    Query\Expr\Join::WITH,
                                    $sq->expr()->eq($f, "{$v}.field")
                                )
                                ->where($where)
                                ->getDql();
                            $subexpr = $q->expr()->eq(sprintf("(%s)",$subquery), 1);
                            $ignoreGlobalNot = true;
                            $useExpr->add($subexpr);
                        }
                    }
                    if ($group !== false) {
                        //add the group if not added yet
                        $expr->add($group);
                    }
                } else {
                    //force a bad expression as the list doesn't exist
                    $expr = $q->expr()->eq('l.id', 0);
                }
                break;
        }

        $string = ($filter->strict) ? $filter->string : "%{$filter->string}%";

        if ($command != $this->translator->trans('mautic.lead.lead.searchcommand.list')) {
            $parameters[$unique] = $string;
        }

        if ($expr && !$ignoreGlobalNot && $filter->not) {
            $expr = $q->expr()->not($expr);
        }

        return array(
            $expr,
            ($returnParameter) ? $parameters : array()
        );

    }

    public function getFilterExpressionFunctions($operator = null)
    {
        $operatorOptions = array(
            '='      =>
                array(
                    'label' => 'mautic.lead.list.form.operator.equals',
                    'func'  => 'eq'
                ),
            '!='     =>
                array(
                    'label' => 'mautic.lead.list.form.operator.notequals',
                    'func'  => 'neq'
                ),
            '&#62;'   =>
                array(
                    'label' => 'mautic.lead.list.form.operator.greaterthan',
                    'func'  => 'gt'
                ),
            '&#62;='   =>
                array(
                    'label' => 'mautic.lead.list.form.operator.greaterthanequals',
                    'func'  => 'gte'
                ),
            '&#60;'    =>
                array(
                    'label' => 'mautic.lead.list.form.operator.lessthan',
                    'func'  => 'lt'
                ),
            '&#60;='   =>
                array(
                    'label' => 'mautic.lead.list.form.operator.lessthanequals',
                    'func'  => 'lte'
                ),
            'empty'  =>
                array(
                    'label' => 'mautic.lead.list.form.operator.isempty',
                    'func'  => 'empty' //special case
                ),
            '!empty' =>
                array(
                    'label' => 'mautic.lead.list.form.operator.isnotempty',
                    'func'  => 'notEmpty' //special case
                ),
            'like'   =>
                array(
                    'label' => 'mautic.lead.list.form.operator.islike',
                    'func'  => 'like'
                ),
            '!like'  =>
                array(
                    'label' => 'mautic.lead.list.form.operator.isnotlike',
                    'func'  => 'notLike'
                )
        );

        return ($operator === null) ? $operatorOptions : $operatorOptions[$operator];
    }
    /**
     * @return array
     */
    public function getSearchCommands()
    {
        return array(
            'mautic.core.searchcommand.is' => array(
                'mautic.lead.lead.searchcommand.isanonymous',
                'mautic.core.searchcommand.ismine',
                'mautic.lead.lead.searchcommand.isunowned',
            ),
            'mautic.lead.lead.searchcommand.list',
            'mautic.core.searchcommand.name',
            'mautic.lead.lead.searchcommand.company',
            'mautic.lead.lead.searchcommand.email',
            'mautic.lead.lead.searchcommand.owner'
        );
    }

    /**
     * @return string
     */
    protected function getDefaultOrder()
    {
       return array(
           array('l.dateAdded', 'ASC')
       );
    }
}
