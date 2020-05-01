<?php

namespace App\Services;

use Illuminate\Container\Container as App;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * ServiceAbstract Class
 *
 */
abstract class ServiceAbstract {

    protected $model;

    public function __construct(App $app) {
        $this->app = $app;
        $this->makeModel();
    }

    abstract function model();

    public function makeModel() {
        $model = $this->app->make($this->model());

        return $this->model = $model;
    }

    public function instance() {
        return $this;
    }

    public function create(array $data) {
        $data = $this->RejectEmpty($data); //exclude empty field

        return $this->model->create($data);
    }

    public function pluck($display, $id) {
        return $this->model->pluck($display, $id);
    }

    public function where(array $where) {
        return $this->model->where($where);
    }

    public function findBy($attribute, $value, $columns = array('*')) {
        return $this->model->where($attribute, '=', $value)->first($columns);
    }

    public function find($id, $columns = array('*')) {
        return $this->model->find($id, $columns);
    }

    public function update(Request $request, array $where) {
        $item = $this->model->where($where)->first();

        $fillable_fields = $this->model->getFillable();

        $fillable_fields = array_flip($fillable_fields);

        $data = array_intersect_key($request->all(), $fillable_fields);

        $data = $this->RejectEmpty($data); //exclude empty field

        $item->update($data);

        return $item;

    }

    public function getAll($columns = null) {
        $columns = is_null($columns)?$this->model->getFillable():$columns;
        $items = $this->model->paginate(10);
        return [
            'columns' => $columns,
            'items'   => $items
        ];
    }

    public function destroy($id){
        $item = $this->model->find($id);
        $item->delete();
    }



    private function RejectEmpty($data) {
        //handle the fields having empty values, and exclude them from query operation
        if (isset($this->rejectEmpty) and (count($this->rejectEmpty) > 0)) {
            foreach ($this->rejectEmpty as $key) {
                if ($data[$key] == '') {
                    Arr::forget($data, $key);
                }

            }
        }

        return $data;
    }

}
