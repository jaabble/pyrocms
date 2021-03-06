<?php namespace Pyro\Module\Streams\Entry;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Pyro\Model\EloquentReflection;

class EntryQueryFilter
{
    protected $query;

    protected $model;

    public function __construct(EntryQueryBuilder $query)
    {
        $this->filterQuery($query);
    }

    protected function filterQuery($query)
    {
        // -------------------------------------
        // Filters (QueryString API)
        // -------------------------------------
        $model = $query->getModel();

        $stream = $model->getStream();

        if (ci()->input->get('filter-' . $stream->stream_namespace . '-' . $stream->stream_slug)) {

            $hasResults = false;

            // Get all URL variables
            $queryStringVariables = ci()->input->get();

            // Loop and process!
            foreach ($queryStringVariables as $filter => $value) {

                // Split into components
                $commands = explode('-', $filter);

                // Filter? namespace ? stream ?
                if ($commands[0] != 'f' or
                    $commands[1] != $stream->stream_namespace or
                    $commands[2] != $stream->stream_slug
                ) {
                    continue;
                }

                $fieldSlug = $commands[3];

                $fieldSlugSegments = explode('|', $fieldSlug);

                $fieldSlug = array_shift($fieldSlugSegments);

                if ($relation = $this->reflection($model)->getRelationClass($fieldSlug)) {

                    $filterByColumns = explode('|', $commands[4]);

                    $foreignKey = $relation->getForeignKey();

                    if (!empty($fieldSlugSegments)) {
                        // Loop through to get the depest relation
                        foreach ($fieldSlugSegments as $nestedRelationSlug) {

                            $relatedModel = $relation->getRelated();

                            if ($nestedRelation = $this->reflection($relatedModel)->getRelationClass(
                                $nestedRelationSlug
                            )
                            ) {
                                $relation = $nestedRelation;
                            }
                        }

                        $otherKey = explode('.', $relation->getForeignKey());
                        $otherKey = array_pop($otherKey);

                    } else {

                        $otherKey = $relation->getParent()->getKeyName();

                    }

                    if (!empty($filterByColumns) and count($commands) == 6) {

                        $constraintType = $commands[5];

                        foreach ($filterByColumns as $filterBy) {
                            $query = $this->constrains(
                                $relation->getRelated()->newQuery(),
                                $constraintType,
                                $filterBy,
                                $value
                            );
                        }

                        $relatedModelResults = $query->get();

                        if (!$relatedModelResults->isEmpty()) {

                            $query->whereIn($foreignKey, array_values($relatedModelResults->lists($otherKey)));

                            $hasResults = true;
                        }
                    }

                } else {

                    $constraintType = $commands[4];

                    $query = $this->constrains($query, $constraintType, $fieldSlug, $value);
                }
            }
        }

        // -------------------------------------
        // Ordering / Sorting (QueryString API)
        // -------------------------------------

        if ($orderBy = ci()->input->get(
            'order-' . $stream->stream_namespace . '-' . $stream->stream_slug
        )
        ) {

            $sort = ci()->input->get(
                'sort-' . $stream->stream_namespace . '-' . $stream->stream_slug,
                'ASC'
            );

            $orderByRelationMethod = Str::camel($orderBy);

            if ($model->hasRelationMethod($orderByRelationMethod)) {

                $orderByRelation = $model->{$orderByRelationMethod}();

                if ($orderByRelation instanceof BelongsTo) {
                    $related = $orderByRelation->getRelated();

                    // @todo - Untested, verify this actually works
                    if ($related instanceof EntryModel) {
                        $stream = $related->getStream();

                        if (!empty($stream->title_column)) {
                            $orderByColumn = $stream->title_column;
                        }
                    } else {
                        $orderByColumn = $related->getOrderByColumn();
                    }

                    $joinColumn = $model->getTable() . '.' . $orderByRelation->getForeignKey();

                    $query->join(
                        $related->getTable(),
                        $joinColumn,
                        '=',
                        $related->getTable() . '.' . $related->getKeyName()
                    )->orderBy(
                            $related->getTable() . '.' . $orderByColumn,
                            $sort
                        );
                }

            } else {
                $query->orderBy($orderBy, $sort);
            }
        }

        return $this;
    }

    protected function reflection($model)
    {
        return new EloquentReflection($model);
    }

    protected function constrains(Builder $query, $constraintType, $filterByColumn, $value)
    {
        // Switch on the restriction
        switch ($constraintType) {

            /**
             * IS
             * results in: filter = value
             */
            case 'is':

                // Gotta have a value for this one
                if (empty($value)) {
                    continue;
                }

                // Do it
                $query->where($filterByColumn, '=', $value);
                break;


            /**
             * ISNOT
             * results in: filter != value
             */
            case 'isnot':

                // Gotta have a value for this one
                if (empty($value)) {
                    continue;
                }

                // Do it
                $query->where($filterByColumn, '!=', $value);
                break;


            /**
             * ISNOT
             * results in: filter != value
             */
            case 'isnot':

                // Gotta have a value for this one
                if (empty($value)) {
                    continue;
                }

                // Do it
                $query->where($filterByColumn, '!=', $value);
                break;


            /**
             * CONTAINS
             * results in: filter LIKE '%value%'
             */
            case 'contains':

                // Gotta have a value for this one
                if (empty($value)) {
                    continue;
                }

                // Do it
                $query->where($filterByColumn, 'LIKE', '%' . $value . '%');
                break;


            /**
             * DOESNOTCONTAIN
             * results in: filter NOT LIKE '%value%'
             */
            case 'doesnotcontain':

                // Gotta have a value for this one
                if (empty($value)) {
                    continue;
                }

                // Do it
                $query->where($filterByColumn, 'NOT LIKE', '%' . $value . '%');
                break;


            /**
             * STARTSWITH
             * results in: filter LIKE 'value%'
             */
            case 'startswith':

                // Gotta have a value for this one
                if (empty($value)) {
                    continue;
                }

                // Do it
                $query->where($filterByColumn, 'LIKE', $value . '%');
                break;


            /**
             * ENDSWITH
             * results in: filter LIKE '%value'
             */
            case 'endswith':

                // Gotta have a value for this one
                if (empty($value)) {
                    continue;
                }

                // Do it
                $query->where($filterByColumn, 'LIKE', '%' . $value);
                break;


            /**
             * ISEMPTY
             * results in: (filter IS NULL OR filter = '')
             */
            case 'isempty':

                $query->where(
                    function ($query) use ($commands, $value) {
                        $query->where($filterByColumn, 'IS', 'NULL');
                        $query->orWhere($filterByColumn, '=', '');
                    }
                );
                break;


            /**
             * ISNOTEMPTY
             * results in: filter > '')
             */
            case 'isnotempty':

                $query->where($filterByColumn, '>', '');
                break;

            default:
                break;
        }

        return $query;
    }
}