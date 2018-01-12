<?php

namespace Tetranz\Select2EntityBundle\Service;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;

class AutocompleteService
{
    /**
     * @var FormFactoryInterface
     */
    private $formFactory;

    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    /**
     * @param FormFactoryInterface $formFactory
     * @param ManagerRegistry      $doctrine
     */
    public function __construct(FormFactoryInterface $formFactory, ManagerRegistry $doctrine){
        $this->formFactory = $formFactory;
        $this->doctrine = $doctrine;
    }   

    /**
     * @param Request                  $request
     * @param string|FormTypeInterface $type
     *
     * @return array
     */
    public function getAutocompleteResults(Request $request, $type){
        $form = $this->formFactory->create($type);
        $fieldOptions = $form->get($request->get('field_name'))->getConfig()->getOptions();

        /** @var EntityRepository $repo */
        $repo = $this->doctrine->getRepository($fieldOptions['class']);

        $term = $request->get('q');

        $query_builder_fields = $fieldOptions['query_builder_fields'];

        $countQB = $repo->createQueryBuilder('e');
        $countQB->select($countQB->expr()->count('e'));
        $countQB = $this->addWhereConditions($countQB, $query_builder_fields, $term);

        $maxResults = $fieldOptions['page_limit'];
        $offset = ($request->get('page', 1) - 1) * $maxResults;

        $resultQb = $repo->createQueryBuilder('e');
        $resultQb = $this->addWhereConditions($resultQb, $query_builder_fields, $term);
        $resultQb
            ->setMaxResults($maxResults)
            ->setFirstResult($offset)
        ;

        if (is_callable($fieldOptions['callback'])) {
            $cb = $fieldOptions['callback'];

            $cb($countQB, $request);
            $cb($resultQb, $request);
        }

        $count = $countQB->getQuery()->getSingleScalarResult();
        $paginationResults = $resultQb->getQuery()->getResult();

        $result = ['results' => null, 'more' => $count > ($offset + $maxResults)];

        $accessor = PropertyAccess::createPropertyAccessor();

        $result['results'] = array_map(function ($item) use ($accessor, $fieldOptions) {
            return ['id' => $accessor->getValue($item, $fieldOptions['primary_key']), 'text' => $accessor->getValue($item, $fieldOptions['property'])];
        }, $paginationResults);

        return $result;
    }

    public function addWhereConditions(QueryBuilder $qb, $query_builder_fields, $term){
        foreach ($query_builder_fields as $field){
            $qb->orWhere('e.'.$field.' LIKE :term'.$field)
                ->setParameter('term'.$field,$term . '%');
        }

        return $qb;
    }
}
