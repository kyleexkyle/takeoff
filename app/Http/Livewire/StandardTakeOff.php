<?php

namespace App\Http\Livewire;

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use NunoMaduro\Collision\Provider;
use function Livewire\str;
use function Symfony\Component\Translation\t;

class StandardTakeOff extends Component
{
    public $job;
    protected $listeners = ['standard' => 'handleJob'];

    public $wind_zone;
    public $snow_load;
    public $eq_zone;
    public $bay_spacing;
    public $building_depth;
    public $front_height;
    public $rear_height;
    public $wind_pressure;
    public $pole_size;
    public $center_pole_length;
    public $is_fully_open = true;
    public $bays;
    public $num_poles;
    public $purlin_member;
    public $num_columns;
    public $num_girts;
    public $num_rafters;
    public $num_props;
    public $internal_rafter_member;
    public $external_rafter_member;
    public $num_purlins_per_bay;
    public $num_overhang_purlins_per_bay;
    public $shed_area;
    public $with_center_pole = false;

    public $concretes;
    public $poles;
    public $rafters;
    public $purlins;
    public $girts_and_columns;
    public $tape;
    public $brace_with_tensioners;
    public $bolts_and_washers;
    public $doors;
    public $overhang_cladding;
    public $fixings_fasteners;

    public function mount()
    {

    }

    public function handleJob($job)
    {
        $this->job = $job;
        $this->wind_zone = ['High Wind' => 1.05, 'Very High Wind' => 1.38, 'Extra High Wind' => 1.69];
        $this->snow_load = ['Nil Snow' => 0.0, '0.9 kPa' => 1.1, '1.35 kPa' => 1.5, '1.8 kPa' => 1.8];
        $this->eq_zone = ['1' => 0.20, '2' => 0.30, '3' => 0.45, '4' => 0.60];

//        if ($this->job == null) {
//            $result = json_decode(Http::acceptJson()->get('https://shedzone.net/api/jobs', ['client_ref' => 'WC-00124']));
//            if (count($result) != 0) {
//                $this->job = (json_decode(json_encode($result[0]), true));
//                $this->job['cladding_end_walls'] = ucwords(str_replace(',', ', ', str_replace(['[', '"', ']'], '', $this->job['cladding_end_walls'])));
//                $this->job['wall_color'] = json_decode($this->job['wall_color'])->name;
//
//            } else {
//                $this->job = null;
//                $this->has_job = false;
//            }
        $this->bays = (str_replace('bay', '', $this->job['num_bay']) + 0);
        $this->bay_spacing = $this->job['bay_spacing'] / 1000;
        $this->building_depth = $this->job['building_depth'] / 1000;
        $this->front_height = $this->job['front_height'] / 1000;
        $this->rear_height = $this->job['rear_height'] / 1000;

        if ($this->job['client_ref'] == 'NE-00138') {
            //TODO Test Variable to have same result on the excel sheet
            $this->building_depth = 12;
            $this->bay_spacing = 3.9;
        }
//        }


        $this->shed_area = $this->shed_area = $this->bays * $this->bay_spacing * $this->building_depth;
        foreach (json_decode($this->job['bay_facades']) as $facade) {
            if ($facade->type != "Fully Open") {
                $this->is_fully_open = false;
            }
        }


        $this->wind_pressure = round(((41 * 41) / (45 * 45)) * $this->wind_zone[$this->job['wind_load']], 2);
        $this->poles = $this->poles($this->job);

        $this->rafters = $this->rafters($this->job);
        $this->purlins = $this->purlins($this->job);
        $this->girts_and_columns = $this->girts_and_columns($this->job);
        $this->tape = $this->tape($this->job);
        $this->concretes = $this->concretes($this->job);
        $this->brace_with_tensioners = $this->brace_with_tensioners($this->job);
        $this->fixings_fasteners = $this->fixings_fasteners($this->job);
        $this->bolts_and_washers = $this->bolts_and_washers($this->job);
        $this->doors = $this->doors($this->job);
        $this->overhang_cladding = $this->overhang_cladding($this->job);

    }

    public function concretes($job)
    {

        $item = Item::where('description', 'Structural Concrete 17.5MPa 19mm')->first();

        if ($job['floor_type'] == "Concrete") {
            $slab = round($this->shed_area * .1 + ($this->shed_area * .1 * .05), 2);
            $concrete['slab'] = ['Structural Concrete 17.5MPa 19mm' => ['sku' => $item->sku, 'unit' => 'm3', 'qty' => $slab]];
        }
        //with 10% wastage
        $post = round(($this->num_poles * (pi() * pow(.3, 2) * 1.2)) + ((8 * (pi() * pow(.3, 2) * 1.2)) * .10), 3);
//        dd($post);
        //with 10% wastage
        $columns = round((($this->num_columns / 2) * (pi() * pow(.2, 2) * .4) + ((($this->num_columns / 2) * (pi() * pow(.2, 2) * .4)) * .10)), 3);

        $concrete['poles'] = ['Structural Concrete 17.5MPa 19mm' => ['sku' => $item != null ? $item->sku : '', 'unit' => 'm3', 'qty' => $post]];
        $concrete['columns'] = ['Structural Concrete 17.5MPa 19mm' => ['sku' => $item != null ? $item->sku : '', 'unit' => 'm3', 'qty' => $columns]];

        return $concrete;
    }

    public function poles($job)
    {


        $Z = $this->eq_zone[$this->job['earthquake_zone']];

        $pole_members = [
            '13.18' => ['diameter' => '150mm', 'description' => 'SED'],
            '18.77' => ['diameter' => '175mm', 'description' => 'SED'],
            '29.80' => ['diameter' => '200mm', 'description' => 'SED'],
            '39.15' => ['diameter' => '225mm', 'description' => 'SED'],
            '56.56' => ['diameter' => '250mm', 'description' => 'SED'],
        ];

//      Ch(T) Class D soil * Z * R (1/100) * N(T,D) default
        $CT = 2.28 * $Z * 0.5 * 1.0;
//      C(T) * ( Sp / ku)
        $CdT = $CT * (1 / 1);
        $wt = 0.25;
        $tributary = $this->bay_spacing * $this->building_depth / 2;
        $wall_area = $this->front_height * $this->bay_spacing / 2;
        $sesmic_weight = $wt * ($tributary + $wall_area);
        $load_top_of_pole = $CdT * $sesmic_weight;
        $share_load = $load_top_of_pole * 2 / 3;
        $avg_pole_height = ($this->front_height + $this->rear_height) / 2.0;

        $net_area = $this->bay_spacing * 2 * $avg_pole_height;
        $ka = $this->get_ka($net_area);

//      Cpi * Kci + Cpe * kce
        $cp_net = 0.3 * 1 + 0.7 * 1;
//      kce * wind pressure * net area * ka
        $wind_load_on_wall = round($cp_net * $this->wind_pressure * $net_area * $ka, 2);

        $clear_span_shared_load = $wind_load_on_wall / 4;
        $clear_span_udl = $clear_span_shared_load / $avg_pole_height;
        $MEu = $share_load * $avg_pole_height;
        $MWu = ($clear_span_udl * ($avg_pole_height ** 2)) / 2;
        $clear_span_member = $this->get_member($pole_members, max($MEu, $MWu));

        $center_pole_shared_load = $wind_load_on_wall / 4.5;
        $center_pole_udl = $center_pole_shared_load / $avg_pole_height;
        $MEu = $share_load * $avg_pole_height;
        $MWu = ($center_pole_udl * ($avg_pole_height ** 2)) / 2;
        $center_pole_member = $this->get_member($pole_members, max($MEu, $MWu));

        $this->pole_size = str_replace('mm', '', $center_pole_member['diameter']) / 1000;

        $member = $job['clearspan'] == 'yes' ? $clear_span_member : $center_pole_member;

        $this->with_center_pole = $this->building_depth > 6 ? true : false;
//      Pole Qty.
        $front_poles = 1 * $this->bays + 1;
        $rear_poles = 1 * $this->bays + 1;
        $center_poles = ($this->building_depth > 6 ? $rear_poles : 0);

        $front_pole_length = $this->front_height + 1.1;
        $rear_pole_length = $this->rear_height + 1.1;
        $this->center_pole_length = $this->rear_height + (($this->front_height - $this->rear_height) / 2) + 1.1;

        $this->num_poles = $front_poles + $rear_poles + $center_poles;

        $ar1 = array($this->get_material_length($front_pole_length) . 'm ' . $member['description'] . ' ' . $member['diameter'] => $front_poles);
        $ar2 = array($this->get_material_length($rear_pole_length) . 'm ' . $member['description'] . ' ' . $member['diameter'] => $rear_poles);
        $ar3 = array($this->get_material_length($this->center_pole_length) . 'm ' . $member['description'] . ' ' . $member['diameter'] => $center_poles);

        $poles = array_merge_recursive($ar1, $ar2, $ar3);


        foreach ($poles as $key => $pole) {
            $item = Item::where('description', 'Pole Round H5 ' . $key)->first();
            if (is_array($pole)) {
                $poles[$key] = ['sku' => $item != null ? $item->sku : '', 'unit' => 'each', 'qty' => array_sum($pole)];
            } else {
                $poles[$key] = ['sku' => $item != null ? $item->sku : '', 'unit' => 'each', 'qty' => ($pole)];
            }
        }

        return $poles;
    }

    public function rafters($job)
    {
//      All Members for internal Rafter (Open)
        $open_internal_members = [
            1 => 'N/A',
            4 => '2/190 x 45 (SG8)',
            5 => '2/240 x 45 (SG8)',
            6 => '2/290 x 45 (SG8)',
            7 => '2/240 x 45 HySpan',
            8 => '2/240 x 63 HySpan LVL',
            9 => '2/300 x 45 HySpan LVL',
            10 => '2/300 x 63 HySpan LVL',
            11 => '2/400 x 45 HySpan LVL',
            12 => '2/400 x 63 HySpan LVL',
            13 => '2/ 600 x45 HySpan LVL',
        ];
        $open_internal_members2 = [
            1 => ['qty' => 0],
            4 => ['qty' => 2, 'width' => 190, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
            5 => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
            6 => ['qty' => 2, 'width' => 290, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
            7 => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'HySpan'],
            8 => ['qty' => 2, 'width' => 240, 'thickness' => 63, 'description' => 'HySpan LVL'],
            9 => ['qty' => 2, 'width' => 300, 'thickness' => 45, 'description' => 'HySpan LVL'],
            10 => ['qty' => 2, 'width' => 300, 'thickness' => 63, 'description' => 'HySpan LVL'],
            11 => ['qty' => 2, 'width' => 400, 'thickness' => 45, 'description' => 'HySpan LVL'],
            12 => ['qty' => 2, 'width' => 400, 'thickness' => 63, 'description' => 'HySpan LVL'],
            13 => ['qty' => 2, 'width' => 600, 'thickness' => 45, 'description' => 'HySpan LVL'],
        ];

        $open_knm_internal_members = [
            '6.91' => ['2/190 x 45 (SG8)', 4],
            '11.03' => ['2/240 x 45 (SG8)', 5],
            '16.11' => ['2/290 x 45 (SG8)', 6],
            '33.3' => ['2/240 x 45 HySpan', 7],
            '46.6' => ['2/240 x 63 HySpan LVL', 8],
            '50.1' => ['2/300 x 45 HySpan LVL', 9],
            '70.2' => ['2/300 x 63 HySpan LVL', 10],
            '84.9' => ['2/400 x 45 HySpan LVL', 11],
            '118.9' => ['2/400 x 63 HySpan LVL', 12],
            '178.6' => ['2/ 600 x45 HySpan LVL', 13],
        ];
        $open_knm_internal_members2 = [
            '6.91' => ['qty' => 2, 'width' => 190, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 4],
            '11.03' => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 5],
            '16.11' => ['qty' => 2, 'width' => 290, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 6],
            '33.3' => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'HySpan', 'num' => 7],
            '46.6' => ['qty' => 2, 'width' => 240, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 8],
            '50.1' => ['qty' => 2, 'width' => 300, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 9],
            '70.2' => ['qty' => 2, 'width' => 300, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 10],
            '84.9' => ['qty' => 2, 'width' => 400, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 11],
            '118.9' => ['qty' => 2, 'width' => 400, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 12],
            '178.6' => ['qty' => 2, 'width' => 600, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 13],
        ];

        $open_el_internal_members = [
            '412' => ['2/190 x 45 (SG8)', 4],
            '829' => ['2/240 x 45 (SG8)', 5],
            '1463' => ['2/290 x 45 (SG8)', 6],
            '1369' => ['2/240 x 45 HySpan', 7],
            '1916' => ['2/240 x 63 HySpan LVL', 8],
            '2673' => ['2/300 x 45 HySpan LVL', 9],
            '3742' => ['2/300 x 63 HySpan LVL', 10],
            '6336' => ['2/400 x 45 HySpan LVL', 11],
            '8870' => ['2/400 x 63 HySpan LVL', 12],
            '21384' => ['2/ 600 x45 HySpan LVL', 13],
        ];
        $open_el_internal_members2 = [
            '412' => ['qty' => 2, 'width' => 190, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 4],
            '829' => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 5],
            '1463' => ['qty' => 2, 'width' => 290, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 6],
            '1369' => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'HySpan', 'num' => 7],
            '1916' => ['qty' => 2, 'width' => 240, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 8],
            '2673' => ['qty' => 2, 'width' => 300, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 9],
            '3742' => ['qty' => 2, 'width' => 300, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 10],
            '6336' => ['qty' => 2, 'width' => 400, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 11],
            '8870' => ['qty' => 2, 'width' => 400, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 12],
            '21384' => ['qty' => 2, 'width' => 600, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 13],
        ];

//      All Members for internal Rafter  (Enclosed)
        $enclosed_internal_members = [
            1 => 'N/A',
            2 => '2/190 x 45 (SG8)',
            3 => '2/240 x 45 (SG8)',
            4 => '2/290 x 45 (SG8)',
            5 => '2/240 x 45 HySpan',
            6 => '2/240 x 63 HySpan LVL',
            7 => '2/300 x 45 HySpan LVL',
            8 => '2/300 x 63 HySpan LVL',
            9 => '2/400 x 45 HySpan LVL',
            10 => '2/400 x 63 HySpan LVL',
            11 => '2/ 600 x45 HySpan LVL',
        ];
        $enclosed_internal_members2 = [
            1 => 'N/A',
            2 => ['qty' => 2, 'width' => 190, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
            3 => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
            4 => ['qty' => 2, 'width' => 290, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
            5 => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'HySpan'],
            6 => ['qty' => 2, 'width' => 240, 'thickness' => 63, 'description' => 'HySpan LVL'],
            7 => ['qty' => 2, 'width' => 300, 'thickness' => 45, 'description' => 'HySpan LVL'],
            8 => ['qty' => 2, 'width' => 300, 'thickness' => 63, 'description' => 'HySpan LVL'],
            9 => ['qty' => 2, 'width' => 400, 'thickness' => 45, 'description' => 'HySpan LVL'],
            10 => ['qty' => 2, 'width' => 400, 'thickness' => 63, 'description' => 'HySpan LVL'],
            11 => ['qty' => 2, 'width' => 600, 'thickness' => 45, 'description' => 'HySpan LVL'],
        ];

        $enclosed_knm_internal_members = [
            '6.91' => ['2/190 x 45 (SG8)', 2],
            '11.03' => ['2/240 x 45 (SG8)', 3],
            '16.11' => ['2/290 x 45 (SG8)', 4],
            '33.3' => ['2/240 x 45 HySpan', 5],
            '46.6' => ['2/240 x 63 HySpan LVL', 6],
            '50.1' => ['2/300 x 45 HySpan LVL', 7],
            '70.2' => ['2/300 x 63 HySpan LVL', 8],
            '84.9' => ['2/400 x 45 HySpan LVL', 9],
            '118.9' => ['2/400 x 63 HySpan LVL', 10],
            '178.6' => ['2/ 600 x45 HySpan LVL', 11],
        ];
        $enclosed_knm_internal_members2 = [
            '6.91' => ['qty' => 2, 'width' => 190, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 2],
            '11.03' => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 3],
            '16.11' => ['qty' => 2, 'width' => 290, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 4],
            '33.3' => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'HySpan', 'num' => 5],
            '46.6' => ['qty' => 2, 'width' => 240, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 6],
            '50.1' => ['qty' => 2, 'width' => 300, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 7],
            '70.2' => ['qty' => 2, 'width' => 300, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 8],
            '84.9' => ['qty' => 2, 'width' => 400, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 9],
            '118.9' => ['qty' => 2, 'width' => 400, 'thickness' => 63, 'description' => 'HySpan LVL', 'num1' => 10],
            '178.6' => ['qty' => 2, 'width' => 600, 'thickness' => 45, 'description' => 'HySpan LVL', 'num1' => 11],
        ];

        $enclosed_el_internal_members = [
            '412' => ['2 / 190 x 45 (SG8)', 2],
            '829' => ['2 / 240 x 45 (SG8)', 3],
            '1369' => ['2 / 240 x 45 HySpan', 5],
            '1463' => ['2 / 290 x 45 (SG8)', 4],
            '1916' => ['2 / 240 x 63 HySpan LVL', 6],
            '2673' => ['2 / 300 x 45 HySpan LVL', 7],
            '3742' => ['2 / 300 x 63 HySpan LVL', 8],
            '6336' => ['2 / 400 x 45 HySpan LVL', 9],
            '8870' => ['2 / 400 x 63 HySpan LVL', 10],
            '21384' => ['2 / 600 x45 HySpan LVL', 11],
        ];
        $enclosed_el_internal_members2 = [
            '412' => ['qty' => 2, 'width' => 190, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 2],
            '829' => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 3],
            '1369' => ['qty' => 2, 'width' => 240, 'thickness' => 45, 'description' => 'HySpan', 'num' => 5],
            '1463' => ['qty' => 2, 'width' => 290, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 4],
            '1916' => ['qty' => 2, 'width' => 240, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 6],
            '2673' => ['qty' => 2, 'width' => 300, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 7],
            '3742' => ['qty' => 2, 'width' => 300, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 8],
            '6336' => ['qty' => 2, 'width' => 400, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 9],
            '8870' => ['qty' => 2, 'width' => 400, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 10],
            '21384' => ['qty' => 2, 'width' => 600, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 11],
        ];

//      All Members for external Rafter
        $knm_external_members = [
            '3.46' => ['190 x 45 (SG8)', 1],
            '4.45' => ['240 x 45 (SG8)', 2],
            '6.58' => ['290 x 45 (SG8)', 3],
            '18.9' => ['240 x 45 HySpan LVL', 4],
            '29.6' => ['300 x 63 HySpan LVL', 5],
            '42.6' => ['360 x 63 HySpan LVL', 6],
        ];
        $knm_external_members2 = [
            '3.46' => ['qty' => 1, 'width' => 190, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 1],
            '4.45' => ['qty' => 1, 'width' => 240, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 2],
            '6.58' => ['qty' => 1, 'width' => 290, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 3],
            '18.9' => ['qty' => 1, 'width' => 240, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 4],
            '29.6' => ['qty' => 1, 'width' => 300, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 5],
            '42.6' => ['qty' => 1, 'width' => 360, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 6],
        ];

        $el_external_members = [
            '206' => ['190 x 45 (SG8)', 1],
            '415' => ['240 x 45 (SG8)', 2],
            '732' => ['290 x 45 (SG8)', 3],
            '958' => ['240 x 45 HySpan LVL', 4],
            '1871' => ['300 x 63 HySpan LVL', 5],
            '3233' => ['360 x 63 HySpan LVL', 6],
        ];
        $el_external_members2 = [
            '206' => ['qty' => 1, 'width' => 190, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 1],
            '415' => ['qty' => 1, 'width' => 240, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 2],
            '732' => ['qty' => 1, 'width' => 290, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet', 'num' => 3],
            '958' => ['qty' => 1, 'width' => 240, 'thickness' => 45, 'description' => 'HySpan LVL', 'num' => 4],
            '1871' => ['qty' => 1, 'width' => 300, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 5],
            '3233' => ['qty' => 1, 'width' => 360, 'thickness' => 63, 'description' => 'HySpan LVL', 'num' => 6],
        ];

        $internal_rafter_span = $job['clearspan'] == 'yes' ? round($this->building_depth - $this->pole_size, 2) : round($this->building_depth - $this->pole_size, 2) / 2;
        $external_rafter_span = round($this->building_depth - $this->pole_size, 2) / 2;
        $limit_to_span = $job['clearspan'] == 'yes' ? 150 : 200;


//      Internal RAFTER design ==========================================================
//      variable constant on sheet ==== (G) Roof and purlins
        $udl = $this->bay_spacing * 0.2;
//      Self Weight estimated
        if ($internal_rafter_span <= 6) {
            $swe = 0.12;
        } elseif ($internal_rafter_span <= 8) {
            $swe = 0.15;
        } else {
            $swe = 0.2;
        }

        $G = $udl + $swe;
        $Q = round(0.25 * $this->bay_spacing, 2);
        $Su = $this->snow_load[$job['snow_load']] * 0.7 * $this->bay_spacing;
//      Cpe for roof uplift avg
        $Cpe = ($this->building_depth >= 2 * $this->front_height ? -0.7 : -0.9);

        $tributary_roof_area = $this->bay_spacing * $internal_rafter_span;
        $ka = $this->get_ka($tributary_roof_area);


//      Kce ,Cpi , Kci respectively
        if ($this->is_fully_open) {
            $cp_nett_uplift = round((($Cpe * 0.9) - (0.7 * 0.9)) * $ka, 2);
        } else {
            $cp_nett_uplift = round($Cpe * $ka * 0.9, 2);
        }
        $Wu = round($cp_nett_uplift * ((41 ** 2 / 45 ** 2) * $this->wind_zone[$job['wind_load']]) * $this->bay_spacing, 2);

//      Load Combinations
        $g = round($G * 1.35 / 0.6, 2);
        $q = round((($G * 1.2) + ($Q * 1.5)) / 0.8, 2);
        $su = round((1.2 * $G) + $Su + (0 * $Q), 2);
        $wu = round((0.9 * $G) + $Wu, 2);
        $max_load_internal = max([abs($g), abs($q), abs($su), abs($wu)]);

//      Moment applied (M*) internal rafter
        $UDL = $max_load_internal * (($internal_rafter_span ** 2) / 8);
        $Qp = 1.2 * $G * $internal_rafter_span ** 2 / 8 + 1.5 * 1.1 * $internal_rafter_span / 4;
        $knm_internal_member = $this->get_member($this->is_fully_open ? $open_knm_internal_members2 : $enclosed_knm_internal_members2, max($UDL, $Qp));


//      Check long term deflection for K2=2 Max deflection in rafter limit to span
        $K2 = $internal_rafter_span / $limit_to_span;
        $el = 5 * 2 * $G * ($internal_rafter_span ** 4) / ($K2 * 384);
        $el_internal_member = $this->get_member($this->is_fully_open ? $open_el_internal_members2 : $enclosed_el_internal_members2, $el);

        $internal_member = $knm_internal_member['num'] >= $el_internal_member['num'] ? $knm_internal_member : $el_internal_member;

//        dd($knm_internal_member[1], $el_internal_member[1]);
        $rafter_with_prop = "N/A";
        if ($this->is_fully_open && $job['clearspan'] == 'yes') {
            $rafter_with_prop = $internal_rafter_span <= 6 ? $open_internal_members2[max($knm_internal_member['num'], $el_internal_member['num']) - 1] : 'N / A';
        } else {
            $rafter_with_prop = $internal_rafter_span <= 6 ? $enclosed_internal_members2[max($knm_internal_member['num'], $el_internal_member['num']) - 1] : 'N / A';
        }

//=================================================================================================================================//

//      External RAFTER design =======================================================================================================//
        $udl = $this->bay_spacing * 0.2 / 2;
//      Self Weight estimated
        if ($external_rafter_span / 2 <= 6) {
            $swe = 0.1;
        } elseif ($internal_rafter_span <= 8) {
            $swe = 0.15;
        } else {
            $swe = 0.2;
        }
        $G = $udl + $swe;
        $Q = 0.25 * $this->bay_spacing / 2;
        $Su = $this->snow_load[$job['snow_load']] * 0.7 * $this->bay_spacing / 2;
//      Cpe for roof uplift avg
        $Cpe = ($this->building_depth / 2 >= 2 * $this->front_height ? -0.7 : -0.9);

        $tributary_roof_area = ($this->bay_spacing / 2) * ($internal_rafter_span);
        $ka = $this->get_ka($tributary_roof_area);

//      variable constant on sheet ==== Kce ,Cpi , Kci respectively
        if ($this->is_fully_open) {
            $cp_nett_uplift = round((($Cpe * 0.9) - (0.7 * 0.9)) * $ka, 2);
        } else {
            $cp_nett_uplift = round($Cpe * $ka * 0.9, 2);
        }
        $Wu = round($cp_nett_uplift * $this->wind_pressure * ($this->bay_spacing / 2), 2);

//      Load Combinations
        $g = round($G * 1.35 / 0.6, 2);
        $q = round((($G * 1.2) + ($Q * 1.5)) / 0.8, 2);
        $su = round((1.2 * $G) + $Su + (0 * $Q), 2);
        $wu = round((0.9 * $G) + $Wu, 2);
        $max_load_internal = max([abs($g), abs($q), abs($su), abs($wu)]);

//      Moment applied (M*) internal rafter
        $UDL = $max_load_internal * ((($external_rafter_span / 2) ** 2) / 8);
        $Qp = 1.2 * $G * ($external_rafter_span / 2) ** 2 / 8 + 1.5 * 1.1 * ($external_rafter_span / 2) / 4;
        $knm_external_member = $this->get_member($knm_external_members2, max($UDL, $Qp));

//      Check long term deflection for K2=2 Max deflection in rafter limit to span
        $K2 = round($external_rafter_span / ($this->is_fully_open ? 200 : 150), 2);
        $el = 5 * 2 * $G * ($external_rafter_span ** 4) / ($K2 * 384);
        $el_external_member = $this->get_member($el_external_members2, $el);

        if ($knm_external_member['num'] >= $el_external_member['num']) {
            $external_member = $knm_external_member;
        } else {
            $external_member = $el_external_member;
        }


//=================================================================================================================================//

        $this->internal_rafter_member = $rafter_with_prop != 'N/A' ? $rafter_with_prop : $internal_member;
        $this->external_rafter_member = $external_member;

//
//        if (str_contains($this->internal_rafter_member, '(SG8)')) {
//            $internal_member = str_replace([' ', '2 / ', '(SG8)'], '', $this->internal_rafter_member) . ' mm Rad MSG8 H3.2 MG Wet ';
//        } else {
//            $internal_member = str_replace('2 / ', '', $this->internal_rafter_member);
//        }
//
//        if (str_contains($this->external_rafter_member, '(SG8)')) {
//            $external_member = str_replace([' ', '2 / ', '(SG8)'], '', $this->external_rafter_member) . ' mm Rad MSG8 H3.2 MG Wet ';
//        } else {
//            $external_member = str_replace('2 / ', '', $this->external_rafter_member);
//        }
        $prop_member = '140x45 mm Rad MSG8 H3.2 MG Wet 3.0 ';
        if ($job['timber_option'] == 'rough sawn') {
            $this->internal_rafter_member['width'] += 10;
            $this->internal_rafter_member['thickness'] += 5;
            $this->external_rafter_member['width'] += 10;
            $this->external_rafter_member['thickness'] += 5;
            $prop_member = '150x50 mm Rad MSG8 H3.2 MG Wet 3.0 ';
        }

        $internal_member = $this->internal_rafter_member['width'] . 'x' . $this->internal_rafter_member['thickness'] . ' mm ' . $this->internal_rafter_member['description'];
        $external_member = $this->external_rafter_member['width'] . 'x' . $this->external_rafter_member['thickness'] . ' mm ' . $this->external_rafter_member['description'];

        $internal = [$internal_member . ' ' . $this->get_material_length(($this->building_depth > 6 ? $this->building_depth / 2 : $this->building_depth)) => ($this->building_depth > 6 ? ($this->bays * 4) - 4 : ($this->bays * 2) - 2)];
        $external = [$external_member . ' ' . $this->get_material_length(($this->building_depth > 6 ? $this->building_depth / 2 : $this->building_depth)) => ($this->building_depth > 6 ? 4 : 2)];

        $rafters = array_merge_recursive($internal, $external);


        foreach ($rafters as $key => $rafter) {
            $member = str_replace('MSG8', 'SG8', $key) . ' [MSG]';
            $item = Item::where('description', $member)->first();

            if (is_array($rafter)) {
                $rafters[$key] = ['sku' => $item != null ? $item->sku : '', 'unit' => 'lght', 'qty' => array_sum($rafter)];
            } else {
                $rafters[$key] = ['sku' => $item != null ? $item->sku : '', 'unit' => 'lght', 'qty' => ($rafter)];
            }
        }
        $this->num_rafters = array_sum($rafters);

        if ($rafter_with_prop != 'N/A') {

            $member = str_replace('MSG8', 'SG8', $prop_member) . '[MSG]';
            $item = Item::where('description', $member)->first();

            $props = ($this->building_depth > 6 ? ($this->bays * 4) - 4 : ($this->bays * 2) - 2) * 2;
            $this->num_props = $props;
            return array('rafters' => $rafters, 'prop' => [$prop_member => ['sku' => $item != null ? $item->sku : '', 'unit' => 'lght', 'qty' => ceil(($props * 1.4142) / 3)]]);
        } else {
            return array('rafters' => $rafters);
            $this->num_props = 0;
        }

    }

    public function purlins($job)
    {
        $purlin_spacing = 0.9;
        $knm_members = [
            '1.70' => ['140x45', 1],
            '3.04' => ['190x45', 2],
            '4.28' => ['240x45', 3],
            '5.11' => ['290x45', 4],
        ];
        $knm_members2 = [
            '1.70' => ['width' => 140, 'thickness' => 45, 'description' => 'Rad SG8 H3.2 MG Wet', 'num' => 1],
            '3.04' => ['width' => 190, 'thickness' => 45, 'description' => 'Rad SG8 H3.2 MG Wet', 'num' => 2],
            '4.28' => ['width' => 240, 'thickness' => 45, 'description' => 'Rad SG8 H3.2 MG Wet', 'num' => 3],
            '5.11' => ['width' => 290, 'thickness' => 45, 'description' => 'Rad SG8 H3.2 MG Wet', 'num' => 4],
        ];

        $el_members = [
            '82' => ['140x45', 1],
            '206' => ['190x45', 2],
            '415' => ['240x45', 3],
            '732' => ['290x45', 4],
        ];
        $el_members2 = [
            '82' => ['width' => 140, 'thickness' => 45, 'description' => 'Rad SG8 H3.2 MG Wet', 'num' => 1],
            '206' => ['width' => 190, 'thickness' => 45, 'description' => 'Rad SG8 H3.2 MG Wet', 'num' => 2],
            '415' => ['width' => 240, 'thickness' => 45, 'description' => 'Rad SG8 H3.2 MG Wet', 'num' => 3],
            '732' => ['width' => 290, 'thickness' => 45, 'description' => 'Rad SG8 H3.2 MG Wet', 'num' => 4],
        ];

//      TODO Loads Enclosed
        $G = (0.20 * $purlin_spacing);
        $Q = round(0.25 * $purlin_spacing, 2);
        $Su = round($this->snow_load[$job['snow_load']] * 0.7 * $purlin_spacing, 2);

        if (!$this->is_fully_open) {
            //      Cp nett	-0.81
            $Wu = round(-0.8 * $this->wind_pressure * $purlin_spacing, 2);
        } else {
            //      Cpe	-0.9 ,Cpi	0.7 ,kce	0.9 ,kci	1
            $cp_nett = (-0.9 * 0.9) - (0.7 * 1);
            $Wu = round($cp_nett * $purlin_spacing * $this->wind_pressure, 2);
        }


        $g = $G * 1.35 / 0.6;
        $q = (($G * 1.2) + ($Q * 1.5)) / 0.8;
        $su = ((1.2 * $G) + $Su + (0 * $Q)) / 0.8;
        $wu = (0.9 * $G) + $Wu;
        $max_load = max(array(abs($g), abs($q), abs($su), abs($wu)));

        $span = $this->bay_spacing - ($this->is_fully_open ? $this->pole_size : $this->pole_size / 2);
        $udl = ($max_load * $span ** 2) / 8;
        $qp = 1.2 * $G * $span ** 2 / 8 + 1.5 * 1.1 * ($this->is_fully_open ? $this->bay_spacing : $span) / 4;
        $knm_member = $this->get_member($knm_members2, max($udl, 1.1, $qp));

        $K2 = $this->bay_spacing / 150;
        $el = round(5 * 2 * $G * $this->bay_spacing ** 4 / ($K2 * 384));
        $el_member = $this->get_member($el_members2, $el);

        if ($knm_member['num'] >= $el_member['num']) {
            $this->purlin_member = $knm_member;
        } else {
            $this->purlin_member = $el_member;
        }

        if ($job['timber_option'] == 'rough sawn') {
            $this->purlin_member['width'] += 10;
            $this->purlin_member['thickness'] += 5;
        }

        $member = $this->purlin_member['width'] . 'x' . $this->purlin_member['thickness'] . ' mm ' . $this->purlin_member['description'];
        $overhang_purlins = 0;
        if ($job['front_overhang'] == 'soffit') {
            $this->num_overhang_purlins_per_bay = 1;
        } elseif ($job['front_overhang'] == 'soffit') {
            $this->num_overhang_purlins_per_bay = 2;
        }

        $this->num_purlins_per_bay = ceil($this->building_depth / $purlin_spacing) + 1;

        $key = str_replace('MSG8', 'SG8', $member) . ' ' . ($this->get_material_length($this->bay_spacing)) . ' [MSG]';
        $item = Item::where('description', $key)->first();

        $purlins = [$member . ' ' . ($this->get_material_length($this->bay_spacing)) => ['sku' => $item != null ? $item->sku : '', 'unit' => 'lght', 'qty' => ($this->num_purlins_per_bay + $this->num_overhang_purlins_per_bay) * $this->bays]];

        return $purlins;

    }

    public function girts_and_columns($job)
    {
        if ($this->building_depth > 6) {
            $with_center_post = true;
            if ($this->building_depth / 2 > 3.6) {
                $with_side_column = true;
            } else {
                $with_side_column = false;
            }
        } elseif ($this->building_depth == 6) {
            $with_center_post = false;
            $with_side_column = true;
        } else {
            $with_side_column = false;
        }


        $with_rear_column = $this->bay_spacing >= 4.8 ? true : false;
        $girt_spacing = 1;

        $girt_members = [
            '1.70 ' => '140x45',
            '3.04 ' => '190x45',
            '4.28 ' => '240x45',
        ];
        $girt_members2 = [
            '1.70 ' => ['width' => 140, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
            '3.04 ' => ['width' => 190, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
            '4.28 ' => ['width' => 240, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
        ];
        $wind_column_members = [
            '3.87' => '2 / 140x45',
            '7.12' => '2 / 190x45',
            '11.36' => '2 / 240x45',
        ];
        $wind_column_members2 = [
            '3.87' => ['width' => 140, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
            '7.12' => ['width' => 190, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
            '11.36' => ['width' => 240, 'thickness' => 45, 'description' => 'Rad MSG8 H3.2 MG Wet'],
        ];

//      Cpe =	0.70  , Cpi =	0.3 (enclosed),Cpi =	0.7 (enclosed) , kce = 	0.9, kci = 	0.9
        $cp_nett = 0.70 * 0.9 + ($this->is_fully_open ? 0.7 : 0.3) * 0.9;
        $Wu = $this->wind_pressure * $girt_spacing * $cp_nett;

        $side_wall = $this->building_depth / 2 - $this->pole_size;
        $rear_wall = $this->bay_spacing - $this->pole_size;

//      GIRTS no wind columns
        $side_m = $Wu * $side_wall ** 2 / 8;
        $side_member = $this->get_member($girt_members2, $side_m);
        $rear_m = ($Wu * ($rear_wall) ** 2) / 8;
        $rear_member = $this->get_member($girt_members2, $rear_m);

//      GIRTS width wind columns
        $with_column_span = (min($side_wall, $rear_wall) < 3.6 ? min($side_wall, $rear_wall) : max($side_wall, $rear_wall) / 2);
        $with_column_m = ($Wu * ($with_column_span) ** 2) / 8;
        $with_column_member = $this->get_member($girt_members2, $with_column_m);

//      Design span length = maximum wall height - 0.3m
        $span_a = $this->front_height - 0.3;
        $area_a = ($span_a * $with_column_span);
        $ka_a = $this->get_ka($area_a);

        $wu_a = $this->wind_pressure * $with_column_span * $cp_nett * $ka_a;
        $m_a = $wu_a * ($span_a * $span_a) / 8;
        $wind_column_a = $this->get_member($wind_column_members2, $m_a);

//      General
//      Design span length = 3/4 sidewall height - 0.3
        $span_b = ($this->front_height - $this->rear_height) * 0.75 + $this->rear_height - 0.3;
        $area_b = ($span_b * $with_column_span);
        $ka_b = $this->get_ka($area_b);

        $wu_b = $this->wind_pressure * $with_column_span * $cp_nett * $ka_b;
        $m_b = $wu_b * ($span_b * $span_b) / 8;
        $wind_column_b = $this->get_member($wind_column_members2, $m_b);

//      Rear wall only
//      Design span length = Rear wall ht - .3
        $span_c = $this->rear_height - 0.3;
        $area_c = ($span_c * $with_column_span);
        $ka_c = $this->get_ka($area_c);

        $wu_c = $this->wind_pressure * $with_column_span * $cp_nett * $ka_c;
        $m_c = $wu_c * ($span_c * $span_c) / 8;
        $wind_column_c = $this->get_member($wind_column_members2, $m_c);

//      Girts Qty
        $rafter_thickness = $this->external_rafter_member['width'] / 1000;
//        if ($with_side_column) {
//            $side = (floor(($this->rear_height - (0.3 + $rafter_thickness)) / $girt_spacing) + 1) * 4;
//            $col_a = $this->center_pole_length + ($this->front_height - $this->center_pole_length) / 2;
//
//            if ($col_a - $rafter_thickness >= ($side / 4 + 0.3)) {
//                $side += 2;
//            }
//            if (($this->center_pole_length - 1.1) - $rafter_thickness >= ($side / 4 + 0.3)) {
//                $side += 2;
//            }
//
//        }

        if ($with_side_column || $this->with_center_pole) {
            $side = (floor(($this->rear_height - (0.3 + $rafter_thickness)) / $girt_spacing) + 1) * 2;
            if (($this->center_pole_length - 1.1) - $rafter_thickness >= ($side / 4 + 0.3)) {
                $side += 1;
            }
        } else {
            $side = (floor(($this->rear_height - (0.3 + $rafter_thickness)) / $girt_spacing) + 1);
        }

        $back = floor(($this->rear_height - (0.3 + $rafter_thickness)) / $girt_spacing) + 1;
        $this->num_girts = $side * 2 + $back * $this->bays;

        if ($job['timber_option'] == 'rough sawn') {
            $side_member['width'] += 10;
            $side_member['thickness'] += 5;
            $rear_member['width'] += 10;
            $rear_member['thickness'] += 5;
            $with_column_member['width'] += 10;
            $with_column_member['thickness'] += 5;
            $wind_column_a['width'] += 10;
            $wind_column_a['thickness'] += 5;
            $wind_column_b['width'] += 10;
            $wind_column_b['thickness'] += 5;
            $wind_column_c['width'] += 10;
            $wind_column_c['thickness'] += 5;
        }

        $side_girt_member = $with_side_column ? $with_column_member : $side_member;
        $side = [$side_girt_member['width'] . 'x' . $side_girt_member['thickness'] . ' mm ' . $side_girt_member['description'] . ' ' . $this->get_material_length(($this->building_depth > 6 ? $this->building_depth / 2 : $this->building_depth)) => $this->building_depth > 6 ? $side * 2 : $side];

        $facade_girt = 0;
        $facade_column = 0;
        foreach (json_decode($job['bay_facades']) as $facade) {
            if ($facade->type == "Roller Door") {
                $facade_girt += 1;
                $this->num_girts += 1;
                $facade_column += 4;
            }
        }
        $back_girt_member = ($with_rear_column ? $with_column_member : $rear_member);
        $back = [$back_girt_member['width'] . 'x' . $back_girt_member['thickness'] . ' mm ' . $back_girt_member['description'] . ' ' . $this->get_material_length($this->bay_spacing) => (($back * $this->bays) + $facade_girt)];
        $girts = array_merge_recursive($side, $back);

        foreach ($girts as $key => $girt) {
            $member = str_replace('MSG8', 'SG8', $key) . ' [MSG]';
            $item = Item::where('description', $member)->first();
            if (is_array($girt)) {
                $girts[$key] = ['sku' => $item != null ? $item->sku : '', 'unit' => 'lght', 'qty' => array_sum($girt)];
            } else {
                $girts[$key] = ['sku' => $item != null ? $item->sku : '', 'unit' => 'lght', 'qty' => ($girt)];
            }
        }

//      Columns Qty
        if ($with_side_column) {
            $door_column = $job['pa_door'] != 'none' ? 2 : 0;
            $side_a = [$wind_column_b['width'] . 'x' . $wind_column_b['thickness'] . ' mm ' . $wind_column_b['description'] . ' ' . $this->get_material_length(($this->center_pole_length - 1.1) + ($this->front_height - ($this->center_pole_length - 1.1)) / 2) => 4 + $facade_column + $door_column];
            if ($this->with_center_pole) {
                $side_b = [$wind_column_b['width'] . 'x' . $wind_column_b['thickness'] . ' mm ' . $wind_column_b['description'] . ' ' . $this->get_material_length(($this->center_pole_length - 1.1) - ($this->front_height - ($this->center_pole_length - 1.1)) / 2) => 4];
            } else {
                $side_b = [$wind_column_b['width'] . 'x' . $wind_column_b['thickness'] . ' mm ' . $wind_column_b['description'] . ' ' . $this->get_material_length(($this->center_pole_length - 1.1)) => 0];
            }
        } else {
            $door_column = $job['pa_door'] != 'none' ? 4 : 0;
            $side_a = [$wind_column_b['width'] . 'x' . $wind_column_b['thickness'] . ' mm ' . $wind_column_b['description'] . ' ' . $this->get_material_length(($this->center_pole_length - 1.1)) => 2 + $facade_column + $door_column];
            $side_b = [$wind_column_b['width'] . 'x' . $wind_column_b['thickness'] . ' mm ' . $wind_column_b['description'] . ' ' . $this->get_material_length(($this->center_pole_length - 1.1)) => 0];
        }


        if ($this->bay_spacing >= 4.2) {
            $back = [$wind_column_c['width'] . 'x' . $wind_column_c['thickness'] . ' mm ' . $wind_column_c['description'] . ' ' . $this->rear_height => $this->bays * 2];
            $columns = (array_merge_recursive($side_a, $side_b, $back));
        } else {
            $columns = (array_merge_recursive($side_a, $side_b));
        }


        foreach ($columns as $key => $column) {

            $member = str_replace('MSG8', 'SG8', $key) . ' [MSG]';
            $item = Item::where('description', $member)->first();

            if (is_array($column)) {
                $columns[$key] = ['sku' => $item != null ? $item->sku : '', 'unit' => 'lght', 'qty' => array_sum($column)];
                $this->num_columns += array_sum($column);
            } else {
                $columns[$key] = ['sku' => $item != null ? $item->sku : '', 'unit' => 'lght', 'qty' => ($column)];
                $this->num_columns += ($column);
            }
        }

        return array('girts' => $girts, 'columns' => $columns);
    }

    public function tape()
    {
        $cross = ceil(($this->building_depth / 4) + 1) * $this->bay_spacing * $this->bays + ($this->building_depth * 2);
        $length = ceil(($this->bay_spacing * $this->bays / 4) + 1) * $this->building_depth + ($this->bay_spacing * $this->bays * 2);
        return ceil(max($cross, $length) / 30);
    }

    public function brace_with_tensioners($job)
    {

        $braces = 0;
        if ($this->bays == 1) {
            $braces = 4;
        } elseif ($this->bays >= 2 && $this->bays <= 4) {
            $braces = 8;
        } elseif ($this->bays >= 5) {
            $braces = 12;
        } else {
            $braces = 16;
        }

        $brace_length = ceil(sqrt(($this->building_depth / 2) ** 2 + $this->bay_spacing ** 2));
        $total_brace_length = $brace_length * $braces;
        $coil_30 = ceil($total_brace_length / 30);
        $multi_brace_with_tensioners['L / Lok Multi - Brace 1mmx53mmx30m coil'] = ['sku'=>'','unit' => 'COIL', 'qty' => $coil_30];
        $multi_brace_with_tensioners['L / lok Multi - Brace Tensioner'] = ['sku'=>'35403200','unit' => 'EACH', 'qty' => $braces];


        if ($job['pa_door'] != 'none') {
            $braces = 6;
        } else {
            $braces = 8;
        }

        $brace_length = ceil(sqrt(($this->building_depth / 2) ** 2 + $this->front_height ** 2));
        $total_brace_length = $brace_length * $braces;

        $coil_30 = (int)($total_brace_length / 30);
        if ($total_brace_length % 30 > 25) {
            $coil_30 += 1;
        } elseif ($total_brace_length % 30 > 20 && $total_brace_length % 30 <= 25) {
            $strip_brace_with_tensioners['L / lok Strip Brace 10m w / out Tensioners '] = ['sku'=>'','unit' => 'COIL', 'qty' => 1];
            $strip_brace_with_tensioners['L / lok Strip Brace 15m w / out Tensioners '] = ['sku'=>'','unit' => 'COIL', 'qty' => 1];
        } elseif ($total_brace_length % 30 > 15 && $total_brace_length % 30 <= 20) {
            $strip_brace_with_tensioners['L / lok Strip Brace 10m w / out Tensioners '] = ['sku'=>'','unit' => 'COIL', 'qty' => 2];
        } elseif ($total_brace_length % 30 > 10 && $total_brace_length % 30 <= 15) {
            $strip_brace_with_tensioners['L / lok Strip Brace 15m w / out Tensioners '] = ['sku'=>'','unit' => 'COIL', 'qty' => 1];;
        } else {
            $strip_brace_with_tensioners['L / lok Strip Brace 10m w / out Tensioners '] = ['sku'=>'','unit' => 'COIL', 'qty' => 1];
        }
        $strip_brace_with_tensioners['L / lok Strip Brace 30m w / out Tensioners'] = ['sku'=>'35402880','unit' => 'COIL', 'qty' => $coil_30];
        $strip_brace_with_tensioners['L / lok Tensioner only for Strip Brace '] = ['sku'=>'35402752','unit' => 'EACH', 'qty' => $braces];

        return array_merge($multi_brace_with_tensioners, $strip_brace_with_tensioners);
    }

    public function bolts_and_washers($job)
    {
        $bolt_length_allowace = 0.03;
        $pole_min_for_cutout = 0.1;
//        dd($this->internal_rafter_member);
        $rafter_thickness = $this->internal_rafter_member['thickness'] / 1000;
        $cut_out = $this->pole_size - $pole_min_for_cutout;
        if ($rafter_thickness * 2 > $cut_out) {
            $pole_length = round($rafter_thickness * 2 - $cut_out + $this->pole_size + $bolt_length_allowace, 2);
        } else {
            $pole_length = round($this->pole_size + $bolt_length_allowace, 2);
        }

        if ($this->with_center_pole) {
            $prop_length = round($rafter_thickness + $pole_min_for_cutout + $bolt_length_allowace, 2);
        }
        //0.9  = 2 prop with thickness of 45;
        $column_length = round(.09 + $bolt_length_allowace, 2);
        $member = $job['wind_load'] == 'High Wind' ? 'M12' : 'M16';

        $pole_prop_bolts = 0;
        $pole_bolts = ['Engineer Bolt & Nut Galv ' . ($pole_length * 1000) . 'mm ' . $member => $pole_prop_bolts += $this->with_center_pole ? ($this->bays + 1) * 8 : ($this->bays + 1) * 4];
        $prop_bolts = ['Engineer Bolt & Nut Galv ' . ($this->with_center_pole ? ($prop_length * 1000) : ($pole_length * 1000)) . 'mm ' . $member => $pole_prop_bolts += $this->with_center_pole ? (($this->bays + 1) - 2) * 8 : 0];
        $column_bolts = ['Engineer Bolt & Nut Galv ' . ($column_length * 1000) . 'mm M12' => $num_column_bolts = $this->num_columns];

        $bolts = array_merge_recursive($pole_bolts, $prop_bolts, $column_bolts);
        foreach ($bolts as $key => $bolt) {
            if (is_array($bolt)) {
                $bolts[$key] = array_sum($bolt);
            }
        }


        $washer_pole_prop = ['Sqr Washer Galv HD 3mm 50x50mm ' . $member => $pole_prop_bolts * 2];
        $washer_column = ['Sqr Washer Galv HD 3mm 50x50mm M12' => $num_column_bolts * 2];

        $washers = array_merge_recursive($washer_pole_prop, $washer_column);

        foreach ($washers as $key => $washer) {
            if (is_array($washer)) {
                $washers[$key] = array_sum($washer);
            }
        }

        return (array_merge($bolts, $washers));

    }

    public function fixings_fasteners($job)
    {

        if ($this->purlin_member['width'] >= 140 && $this->purlin_member['width'] <= 190 && $this->purlin_member['thickness'] == 45) {
            $hanger_member = '47x120mm';
            $hanger_nails = 16;
            $hanger_screws = 8;
        } elseif ($this->purlin_member['width'] >= 150 && $this->purlin_member['width'] <= 200 && $this->purlin_member['thickness'] == 50) {
            $hanger_member = '52x120mm';
            $hanger_nails = 16;
            $hanger_screws = 8;
        } elseif ($this->purlin_member['width'] >= 240 && $this->purlin_member['width'] <= 290 && $this->purlin_member['thickness'] == 45) {
            $hanger_member = '47x190mm';
            $hanger_nails = 24;
            $hanger_screws = 16;
        } elseif ($this->purlin_member['width'] >= 250 && $this->purlin_member['width'] <= 300 && $this->purlin_member['thickness'] == 50) {
            $hanger_member = '52x190mm';
            $hanger_nails = 24;
            $hanger_screws = 16;
        }

//
        $strap_rags = ['Bowmac Strap Rag B75' => ['sku' => '35501568', 'usage' => 'post', 'unit' => 'each', 'qty' => $this->num_columns]];
        $nail_plates = ['Tylok Nail Plate 68x120mm 4T10' => ['sku' => '35404608', 'usage' => 'purlins', 'unit' => 'each', 'qty' => $this->add_wastage($this->bays * 4, .05)]];
        $cpc80 = ['L / lok Concealed Purlin Cleat CPC80' => ['sku' => '35409792', 'usage' => 'purlins', 'unit' => 'each', 'qty' => $num_cpc80 = (($this->bays * 2) - 2) * 2]];

        $num_hanger = (($this->num_purlins_per_bay - 2) * $this->bays) * 2;
        $joist_hanger = ['L / lok Joist Hanger Pre - galv ' . $hanger_member => ['sku' => '35406080', 'usage' => 'purlins', 'unit' => 'each', 'qty' => $this->add_wastage($num_hanger, .05)]];
        $purlin_screws = ['Type 17 Screw 14g x 35mm' => ['sku' => '', 'usage' => 'purlins', 'unit' => 'each', 'qty' => $this->add_wastage($hanger_screws * $num_hanger, .05) + ($num_cpc80 * 8)]];

        //4 represents the girtplates on each end pole for each end wall rafter
        $num_girt_plates = (($this->num_girts * 2) + $this->num_columns + $this->num_props + 4);
        $girt_plates = ['L /Lok Girt Plate Galv' => ['sku' => '', 'usage' => 'column', 'unit' => 'each', 'qty' => $this->add_wastage($num_girt_plates, .05)]];
        $column_screws = ['Type 17 Screw 14g x 35mm ' => ['sku' => '', 'usage' => 'column', 'unit' => 'each', 'qty' => $this->add_wastage($num_girt_plates * 6, .05)]];

        $fixings = array_merge($strap_rags, $nail_plates, $cpc80, $joist_hanger, $purlin_screws, $girt_plates, $column_screws);

//        foreach ($fixings as $key => $fixing) {
//            $item = Item::where('description', $key)->first();
//            $fixings[$key]['sku'] = $item != null ? $item->sku : '';
//        }
        return $fixings;
    }

    public function doors($job)
    {
        $roller_door_opener = 0;
        $roller_doors = [];
        $facades = json_decode($job['bay_facades']);
//        $facades[0]->height = "3.0";
        $roller_doors = [];
        foreach ($facades as $facade) {
            if ($facade->type == "Roller Door") {
                $roller_door_opener++;
                $roller_doors = array_merge_recursive($roller_doors, ['Garage Roller Door ' . $facade->height . 'h x ' . $facade->width . 'w' => 1]);
            }
        }

        $roller_door_opener = ($roller_door_opener != 0 ? ['Roller Door Auto Opener Unit' => ['unit' => 'each', 'qty' => $roller_door_opener]] : []);

        foreach ($roller_doors as $key => $roller_door) {
            if (is_array($roller_door)) {
                $roller_doors[$key] = ['unit' => 'EACH', 'qty' => array_sum($roller_door)];
            } else {
                $roller_doors[$key] = ['unit' => 'EACH', 'qty' => $roller_door];
            }
        }

        $pa_door = [];
        if ($job['pa_door'] != 'none') {
            $pa_door = ['PA Door 2.1h x 0.81w' => ['unit' => 'EACH', 'qty' => 1]];
        }
        $doors = array_merge($roller_doors, $roller_door_opener, $pa_door);

        return $doors;

    }

    public function overhang_cladding($job)
    {
        $sheet_length = 2.4;
        $sheet_width = 1.2;
        if ($job['front_overhang'] == 'soffit') {
            $soffit_area = $this->bay_spacing * $this->bays * 1;
            $sheet_area = $sheet_length * $sheet_width;
            $sheets = (ceil($soffit_area / $sheet_area + ($soffit_area / $sheet_area * .1)));
            return (['Hardiflex Flat Sheet 4.5x2400x1200mm SOFFIT' => ['unit' => 'sht', 'qty' => $sheets]]);

        }
        return array();
    }

    public function get_ka($area)
    {
        switch ($area) {
            case $area <= 10:
                return $ka_c = 1;
                break;
            case $area <= 25:
                return $ka_c = 1 - ($area - 10) * 0.0066666;
                break;
            case $area <= 100:
                return $ka_c = 0.9 - ($area - 25) * 0.001333;
                break;
            default:
                return $ka_c = 0.8;
                break;
        }
    }

    public function get_member($members, $demand)
    {
        foreach ($members as $key => $member) {
            $key2 = array_keys($members, next($members));
            if (count($key2) != 0) {
                if ($key >= $demand && $key2[0] > $demand) {
                    return $members[$key];
                }
            } elseif ($key >= $demand) {
                return $members[$key];
            }
        }
        return null;
    }

    public function get_material_length($length, $smallest = 3.0, $highest = 6.0, $interval = .6)
    {
        if ($length == $highest) {

            return number_format($length, 1, '.', '');
        }
        for ($i = $smallest; $i <= $highest; $i += $interval) {
            if ($length <= $i) {

                return number_format($i, 1, '.', '');
            }
        }
    }

    public function add_wastage($qty, $wastage = 0)
    {
        return ceil($qty += $qty * $wastage);
    }

    public function render()
    {

        return view('livewire.standard-take-off');
    }


}