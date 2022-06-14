<?php

namespace PowerComponents\LivewirePowerGrid;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Database\Eloquent as Eloquent;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Pagination\{AbstractPaginator};
use Illuminate\Support as Support;
use Livewire\{Component, WithPagination};
use PowerComponents\LivewirePowerGrid\Helpers\{Collection, Helpers, Model, SqlSupport};
use PowerComponents\LivewirePowerGrid\Themes\ThemeBase;
use PowerComponents\LivewirePowerGrid\Traits\{BatchableExport,
    Checkbox,
    Exportable,
    Filter,
    Listeners,
    PersistData,
    WithSorting
};

class PowerGridComponent extends Component
{
    use WithPagination;
    use Exportable;
    use WithSorting;
    use Checkbox;
    use HasAttributes;
    use Filter;
    use BatchableExport;
    use PersistData;
    use Listeners;

    public array $headers = [];

    public string $search = '';

    public array $columns = [];

    public array $filtered = [];

    public string $primaryKey = 'id';

    public bool $isCollection = false;

    public string $currentTable = '';

    public Eloquent\Collection|array|Eloquent\Builder $datasource;

    public Support\Collection $withoutPaginatedData;

    public array $relationSearch = [];

    public bool $ignoreTablePrefix = true;

    public string $tableName = 'default';

    public bool $headerTotalColumn = false;

    public bool $footerTotalColumn = false;

    public array $setUp = [];

    public array $inputRangeConfig = [];

    public bool $showErrorBag = false;

    public string $softDeletes = '';

    protected ThemeBase $powerGridTheme;

    public function showCheckBox(string $attribute = 'id'): PowerGridComponent
    {
        $this->checkbox          = true;
        $this->checkboxAttribute = $attribute;

        return $this;
    }

    public function mount(): void
    {
        foreach ($this->setUp() as $setUp) {
            $this->setUp[$setUp->name] = $setUp;
        }

        if (isBootstrap5()) {
            unset($this->setUp['detail']);
        }

        foreach ($this->inputRangeConfig() as $field => $config) {
            $this->inputRangeConfig[$field] = $config;
        }

        $this->columns = $this->columns();

        $this->resolveTotalRow();

        $this->resolveFilters();

        $this->restoreState();
    }

    /**
     * Apply checkbox, perPage and search view and theme
     * @return array
     */
    public function setUp(): array
    {
        return [];
    }

    public function inputRangeConfig(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [];
    }

    private function resolveTotalRow(): void
    {
        collect($this->columns)->each(function (Column $column) {
            $hasHeader = $column->sum['header'] || $column->count['header'] || $column->min['header'] || $column->avg['header'] || $column->max['header'];
            $hasFooter = $column->sum['footer'] || $column->count['footer'] || $column->min['footer'] || $column->avg['footer'] || $column->max['footer'];

            if ($hasHeader) {
                $this->headerTotalColumn = true;
            }
            if ($hasFooter) {
                $this->footerTotalColumn = true;
            }
        });
    }

    /**
     * @throws Exception
     */
    public function render(): Application|Factory|View
    {
        /** @var ThemeBase $themeBase */
        $themeBase = PowerGrid::theme($this->template() ?? powerGridTheme());

        $this->powerGridTheme = $themeBase->apply();

        $this->columns = collect($this->columns)->map(function ($column) {
            return (object) $column;
        })->toArray();

        $this->relationSearch = $this->relationSearch();

        $data = $this->fillData();

        if (method_exists($this, 'initActions')) {
            $this->initActions();
            if (method_exists($this, 'header')) {
                $this->headers = $this->header();
            }
        }

        return $this->renderView($data);
    }

    public function template(): ?string
    {
        return null;
    }

    public function relationSearch(): array
    {
        return [];
    }

    public function updatedSearch(): void
    {
        $this->gotoPage(1);
    }

    /**
     * @throws Exception
     */
    public function fillData(): AbstractPaginator|Support\Collection
    {
        /** @var Eloquent\Builder|Support\Collection|Eloquent\Collection $datasource */
        $datasource = (!empty($this->datasource)) ? $this->datasource : $this->datasource();

        $this->isCollection = is_a((object) $datasource, Support\Collection::class);

        if ($this->isCollection) {
            $filters = Collection::query($this->resolveCollection($datasource))
                ->setColumns($this->columns)
                ->setSearch($this->search)
                ->setFilters($this->filters)
                ->filterContains()
                ->filter();

            $results = $this->applySorting($filters);

            if ($this->headerTotalColumn || $this->footerTotalColumn) {
                $this->withoutPaginatedData = $results->values()
                    ->map(fn ($item) => (array) $item);
            }

            if ($results->count()) {
                $this->filtered = $results->pluck($this->primaryKey)->toArray();

                $paginated = Collection::paginate($results, intval(data_get($this->setUp, 'footer.perPage')));
                $results   = $paginated->setCollection($this->transform($paginated->getCollection()));
            }

            return $results;
        }

        /** @phpstan-ignore-next-line */
        $this->currentTable = $datasource->getModel()->getTable();

        $sortField = Support\Str::of($this->sortField)->contains('.') || $this->ignoreTablePrefix
            ? $this->sortField : $this->currentTable . '.' . $this->sortField;

        /** @var Eloquent\Builder $results */
        $results = $this->resolveModel($datasource)
            ->where(function (Eloquent\Builder $query) {
                Model::query($query)
                    ->setInputRangeConfig($this->inputRangeConfig)
                    ->setColumns($this->columns)
                    ->setSearch($this->search)
                    ->setRelationSearch($this->relationSearch)
                    ->setFilters($this->filters)
                    ->filterContains()
                    ->filter();
            });

        $results = self::applySoftDeletes($results);

        $results = self::applyWithSortStringNumber($results, $sortField);

        $results = $results->orderBy($sortField, $this->sortDirection);

        self::applyTotalColumn($results);

        $results = self::applyPerPage($results);

        self::resolveDetailRow($results);

        $this->total = $results->total();

        return $results->setCollection($this->transform($results->getCollection()));
    }

    private function applyTotalColumn(Eloquent\Builder $results): void
    {
        if ($this->headerTotalColumn || $this->footerTotalColumn) {
            $this->withoutPaginatedData = $this->transform($results->get());
        }
    }

    /**
     * @throws Exception
     */
    private function applyWithSortStringNumber(Eloquent\Builder $results, string $sortField): Eloquent\Builder
    {
        if (!$this->withSortStringNumber) {
            return $results;
        }

        $sortFieldType = SqlSupport::getSortFieldType($sortField);

        if (SqlSupport::isValidSortFieldType($sortFieldType)) {
            $results->orderByRaw(SqlSupport::sortStringAsNumber($sortField) . ' ' . $this->sortDirection);
        }

        return $results;
    }

    private function applyPerPage(Eloquent\Builder $results): LengthAwarePaginator
    {
        $perPage = intval(data_get($this->setUp, 'footer.perPage'));
        if ($perPage > 0) {
            return $results->paginate($perPage);
        }

        return $results->paginate($results->count());
    }

    private function resolveDetailRow(LengthAwarePaginator $results): void
    {
        if (!isset($this->setUp['detail'])) {
            return;
        }

        collect($results->items())
            ->each(function ($model) {
                $state = data_get($this->setUp, 'detail.state.' . $model->id, false);
                data_set($this->setUp, 'detail.state.' . $model->id, $state);
            });
    }

    /**
     * @return null
     */
    public function datasource()
    {
        return null;
    }

    /**
     * @throws Exception
     */
    private function resolveCollection(array|Support\Collection|Eloquent\Builder|null $datasource = null): Support\Collection
    {
        if (!boolval(config('livewire-powergrid.cached_data', false))) {
            return new Support\Collection($this->datasource());
        }

        return cache()->rememberForever($this->id, function () use ($datasource) {
            if (is_array($datasource)) {
                return new Support\Collection($datasource);
            }
            if (is_a((object) $datasource, Support\Collection::class)) {
                return $datasource;
            }

            /** @var array $datasource */
            return new Support\Collection($datasource);
        });
    }

    private function transform(Support\Collection $results): Support\Collection
    {
        if (!is_a((object) $this->addColumns(), PowerGridEloquent::class)) {
            return $results;
        }

        return $results->map(function ($row) {
            $addColumns = $this->addColumns();

            $columns = $addColumns->columns;

            $columns = collect($columns);

            /** @phpstan-ignore-next-line */
            $data = $columns->mapWithKeys(fn ($column, $columnName) => (object) [$columnName => $column((object) $row)]);

            if (count($this->actionRules())) {
                $rules = resolve(Helpers::class)->resolveRules($this->actionRules(), (object) $row);
            }

            $mergedData = $data->merge($rules ?? []);

            return $row instanceof Eloquent\Model
                ? tap($row)->forceFill($mergedData->toArray())
                : (object) $mergedData->toArray();
        });
    }

    public function addColumns(): PowerGridEloquent
    {
        return PowerGrid::eloquent();
    }

    public function actionRules(): array
    {
        return [];
    }

    private function resolveModel(array|Support\Collection|Eloquent\Builder|null $datasource = null): Support\Collection|array|null|Eloquent\Builder
    {
        if (blank($datasource)) {
            return $this->datasource();
        }

        return $datasource;
    }

    private function renderView(AbstractPaginator|Support\Collection $data): Application|Factory|View
    {
        /** @phpstan-ignore-next-line */
        return view($this->powerGridTheme->layout->table, [
            'data'  => $data,
            'theme' => $this->powerGridTheme,
            'table' => 'livewire-powergrid::components.table',
        ]);
    }

    public function checkedValues(): array
    {
        return $this->checkboxValues;
    }

    public function updatedPage(): void
    {
        $this->checkboxAll = false;
    }

    /**
     * @throws Exception
     */
    public function toggleColumn(string $field): void
    {
        $this->columns = collect($this->columns)->map(function ($column) use ($field) {
            if (data_get($column, 'field') === $field) {
                data_set($column, 'hidden', !data_get($column, 'hidden'));
            }

            return (object) $column;
        })->toArray();

        $this->persistState('columns');
    }

    public function toggleDetail(string $id): void
    {
        data_set($this->setUp, "detail.state.$id", !boolval(data_get($this->setUp, "detail.state.$id")));
    }

    public function softDeletes(string $softDeletes): void
    {
        $this->softDeletes = $softDeletes;
    }

    private function applySoftDeletes(Eloquent\Builder $results): Eloquent\Builder
    {
        return match ($this->softDeletes) {
            'withTrashed' => $results->withTrashed(),
            'onlyTrashed' => $results->onlyTrashed(),
            default       => $results
        };
    }

    /**
     * @return array
     */
    protected function getListeners()
    {
        return [
            'pg:datePicker-' . $this->tableName   => 'datePikerChanged',
            'pg:editable-' . $this->tableName     => 'inputTextChanged',
            'pg:toggleable-' . $this->tableName   => 'toggleableChanged',
            'pg:multiSelect-' . $this->tableName  => 'multiSelectChanged',
            'pg:toggleColumn-' . $this->tableName => 'toggleColumn',
            'pg:eventRefresh-' . $this->tableName => '$refresh',
            'pg:softDeletes-' . $this->tableName  => 'softDeletes',
        ];
    }
}
