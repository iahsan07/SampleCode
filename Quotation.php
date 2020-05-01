<?php

/**
 * Created by Soft Pyramid.
 * User: fakhar
 */

namespace App\Browns\Models;

use App\Browns\Models\ApplicationForm\ApplicationForm;
use App\Browns\Models\Traits\ExpiredQuote;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Venturecraft\Revisionable\RevisionableTrait;

class Quotation extends Model {

	use SoftDeletes;
	use ExpiredQuote;
	use RevisionableTrait;

	const TYPE_YES = 'Yes';
	const TYPE_NO = 'No';

	const ALL = 'All';
	const ME = 'Me';
	const MY_TEAM = 'My Team';
	const MY_STUDENTS = 'My Students';

	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $keepRevisionOf = ['name', 'status'];
	protected $table = 'quotations';
//	protected $with = array('services', 'exams', 'programsModel', 'addons', 'experiences', 'paymentMethod', 'healths', 'quotationAccommodations', 'accommodationAddons', 'accommodationServices', 'internships', 'accommodationPreferences', 'transportationTypes', 'transportationAddons', 'student', 'Owner', 'createdBy', 'installments', 'campus', 'country', 'application');

	//required for front end search
	protected $appends = ['selected', 'oshc', 'is_student_visa', 'policies', 'owner_id'];

    /**
     * Get the owner_id - which actually get the owner column from table
     *
     * @return bool
     */
    public function getOwnerIdAttribute()
    {
        return $this->attributes['owner'];
    }

    /**
     * Set the owner_id - which actually set the owner column from table
     *
     * @param $value
     */
    public function setOwnerIdAttribute($value)
    {
        $this->attributes['owner'] = $value;
    }

	function getPoliciesAttribute() {
		$user = auth('api')->user();
		if (!$user) //user is not coming from api
		{
			$user = auth()->user();
		}

		return [
			'can_transfer' => $user->can('transfer_quote', $this),
		];

	}

	function getSelectedAttribute() {
		return false;
	}

	/**
	 * Attributes that should be mass-assignable.
	 *
	 * @var array
	 */
	protected $fillable = ['country_id', 'age_status', 'name'];

	public static function getTypes() {
		return [
			self::TYPE_YES => self::TYPE_YES,
			self::TYPE_NO => self::TYPE_NO,
		];
	}
	public static function getOwnerQuote() {
		return [

			self::ME => self::ME,
			self::MY_TEAM => self::MY_TEAM,
			self::MY_STUDENTS => self::MY_STUDENTS,
			self::ALL => self::ALL,
		];
	}
	public function getCreatedAtAttribute($created) {

		return Carbon::parse($created)->format('d/m/y');
	}
	public function getUpdatedAtAttribute($updated) {

		return Carbon::parse($updated)->format('d/m/y');
	}
	public function getExpiryDateAttribute($expired) {
		if ($expired == null) {
			return null;
		} else {
			return Carbon::parse($expired)->format('d/m/y');
		}

	}

	public function visas() {
		return $this->belongsTo(Visa::class, 'visa_id');
	}

	public function country() {
		return $this->belongsTo(Country::class, 'country_id');
	}

	public function programs() {
		return $this->belongsToMany(Program::class, 'quotation_program')->withPivot('length', 'start_date', 'end_date', 'price', 'randnum', 'discount_price', 'discount_pricebook_id');
	}
	public function programsModel() {
		return $this->hasMany('App\Browns\Models\Quotation\Program\Program', 'quotation_id');
	}

	public function services() {
		return $this->belongsToMany(FeeService::class, 'quotation_service', 'quotation_id', 'service_id')->withPivot('price', 'program_id', 'randnum', 'discount_price', 'length');
	}

	public function exams() {
		return $this->belongsToMany(Exam::class, 'quotation_exam')->withPivot('program_id', 'randnum', 'discount_price');
	}

	public function addons() {
		return $this->belongsToMany(FeeGeneralAddon::class, 'quotation_addon', 'quotation_id', 'addon_id');
	}

	public function experiences() {
		return $this->belongsToMany(FeeExperience::class, 'quotation_experience', 'quotation_id', 'experience_id');
	}

	public function healths() {
		return $this->belongsToMany(OSHCFee::class, 'quotation_health', 'quotation_id', 'oshc_id')->withPivot('price', 'length', 'cover', 'start_date', 'end_date', 'cover_start_week', 'custom_date');
	}

	public function internships() {
		return $this->belongsToMany(Internship::class, 'quotation_internship', 'quotation_id', 'internship_id')->withPivot('fee', 'length');
	}

	public function accommodations() {
		return $this->belongsToMany(Accommodation::class, 'quotation_accommodation', 'quotation_id', 'accommodation_id')->withPivot('price', 'checkin', 'checkout', 'randnum', 'discount_price', 'campus_id');
	}

	public function quotationAccommodations() {
		return $this->hasMany(Quotation\Accommodation\Accommodation::class, 'quotation_id');
	}

	public function accommodationAddons() {
		return $this->belongsToMany(Accommodation\Addon::class, 'quotation_accommodationaddon', 'quotation_id', 'accommodationaddon_id')->withPivot('randnum', 'accommodation_id', 'price', 'discount_price', 'checkin', 'checkout', 'is_broken');
	}

	public function accommodationAddonsModel() {
		return $this->hasMany(Quotation\Accommodation\Addon::class, 'quotation_id');
	}

	public function accommodationAddonsAccommodation() {
		return $this->belongsToMany(Accommodation::class, 'quotation_accommodationaddon', 'quotation_id', 'accommodation_id');
	}

	public function accommodationServices() {
		return $this->belongsToMany(Accommodation\Service::class, 'quotation_accommodationservice', 'quotation_id', 'accommodationservice_id')->withPivot('price', 'randnum', 'accommodation_id', 'discount_price', 'checkin', 'checkout');
	}

	public function accommodationServicesModel() {
		return $this->hasMany(Quotation\Accommodation\Service::class, 'quotation_id');
	}

	public function accommodationPreferences() {
		return $this->belongsToMany(Accommodation\Preference::class, 'quotation_accommodationpreference', 'quotation_id', 'accommodationpref_id')->withPivot('randnum', 'accommodation_id', 'price', 'discount_price', 'checkin', 'checkout', 'is_broken');
	}
	public function accommodationPreferencesModel() {
		return $this->hasMany(Quotation\Accommodation\Preference::class, 'quotation_id');
	}

	public function transportationTypes() {
		return $this->belongsToMany(TransportationType::class, 'quotation_transportation', 'quotation_id', 'transportationtypes_id')->withPivot('randnum', 'accommodation_id', 'price', 'campus_from', 'campus_to', 'discount_price');
	}
	public function transportationsModel() {
		return $this->hasMany(Quotation\Transportation\Transportation::class, 'quotation_id');
	}

	public function transportationAddons() {
		return $this->belongsToMany(TransportationAddon::class, 'quotation_transportation_addon', 'quotation_id', 'transportationaddon_id')->withPivot('randnum', 'accommodation_id', 'trans_name');
	}

	public function transportationAddonsModel() {
		return $this->hasMany(Quotation\Transportation\Addon::class, 'quotation_id');
	}

	public function student() {
		return $this->belongsTo(User::class, 'user_id');
	}

	public function Owner() {
		return $this->belongsTo(User::class, 'owner');
	}

	public function createdBy() {
		return $this->belongsTo(User::class, 'created_by');
	}

	public function updateBy() {
		return $this->belongsTo(User::class, 'update_by');
	}

	public function paymentMethod() {
		return $this->belongsTo(PaymentMethod::class, 'paymentmethods_id');
	}

	public function user() {
		return $this->belongsTo(User::class, 'user_id');
	}

	public function campus() {
		return $this->belongsTo(Campus::class, 'campus_id');
	}

	public function application() {
		return $this->hasOne(ApplicationForm::class, 'quote_id');
	}

	public function applications() {
		return $this->hasMany(ApplicationForm::class, 'quote_id');
	}

	public function installments() {
		return $this->hasMany(QuotationInstallment::class, 'quotation_id');
	}

	public function serviceInstallments() {
		return $this->hasMany(QuotationServiceInstallment::class, 'quotation_id');
	}

	public function getDataTableData() {
		{

			$query = $this->query()->setEagerLoads([])->with( 'application');

			if (Auth::user()->isOfType(STUDENT)) {
				$query = $query->where('user_id', Auth::user()->id)->where('student_visible', true);
			} else {
				$query = $query;
			}

			if(isset($_COOKIE['page_quote_index_quote-status-selector']) && !empty($_COOKIE['page_quote_index_quote-status-selector'])){
				request()->merge(['status_selector' => explode(',',$_COOKIE['page_quote_index_quote-status-selector'])]);
			}

			if (request()->filled('status_selector')) {
				//advance filters
				$query->whereIn('status', request('status_selector'));
			}

			if (request('expiry_start') != '' && request('expiry_end') != '') {
				//advanced filters
				$expirystart = Carbon::parse(request('expiry_start'));
				$expiryend = Carbon::parse(request('expiry_end'));
				$query->whereBetween('expiry_date', array($expirystart, $expiryend))->where('status', '!=', 'Converted');
			} elseif (request('expiry_start') != '') {
				$expirystart = Carbon::parse(request('expiry_start'));
				$query->whereDate('expiry_date', '>=', $expirystart)->where('status', '!=', 'Converted');
			} elseif (request('expiry_end') != '') {
				$expiryend = Carbon::parse(request('expiry_end'));
				$query->whereDate('expiry_date', '<=', $expiryend)->where('status', '!=', 'Converted');
			}

			if (request()->filled('create_start') && request()->filled('create_end')) {

				$dateFrom = Carbon::parse(request('create_start').' 00:00:00');
				$dateTo   = Carbon::parse(request('create_end').' 23:59:59');
				$query->whereBetween('created_at', [$dateFrom, $dateTo]);

			} else if (request()->filled('create_start')) {

                $dateFrom = Carbon::parse(request('create_start'));
				$query->whereDate('created_at', '>=', $dateFrom);

				} else if (request()->filled('create_end')) {

                $dateTo = Carbon::parse(request('create_end'));
				$query->whereDate('created_at', '<=', $dateTo);
			}

			if (request()->filled('quote-selectors')) {
				if (Auth::user()->isOfType(AGENT)) {
					if (request('quote-selectors') == self::ALL) {
						$team = User::whereParentId(auth()->user()->id)->pluck('id');
						$team->push(["id" => Auth::user()->id]);
						$query->whereIn('owner', $team);
					}
					if (request('quote-selectors') == self::ME) {
						$query->where('owner', Auth::user()->id);
					} elseif (request('quote-selectors') == self::MY_TEAM) {
						$allteamids = $this->allTeamMemberids();
						$team = $query->whereIn('owner', $allteamids);
						$query = $team;
					} elseif (request('quote-selectors') == self::MY_STUDENTS) {
						$students = Quotation::whereHas('Owner', function ($query) {
							$query->where('parent_id', auth()->user()->id)->whereHas('user_type', function ($query) {
								$query->where('slug', 'student');
							});
						});
						$query = $students;
					}
				} else {
					if (request('quote-selectors') == self::ALL) {
						$query = $query;
					}
					if (request('quote-selectors') == self::ME) {
						$query->where('owner', Auth::user()->id);
					} elseif (request('quote-selectors') == self::MY_TEAM) {
						$allteamids = $this->allTeamMemberids();
						$team = $query->whereIn('owner', $allteamids);
						$query = $team;
					} elseif (request('quote-selectors') == self::MY_STUDENTS) {
						$students = Quotation::whereHas('Owner', function ($query) {
							$query->where('parent_id', auth()->user()->id)->whereHas('user_type', function ($query) {
								$query->where('slug', 'student');
							});
						});
						$query = $students;
					}

				}

			}

			if (request()->filled('status')) {
				//enabled/disabled filter
				if (request('status') == 'all') {
					$query = $query->withTrashed();
				}

				if (request('status') == 'disabled') {
					// enabled/disabled filter with search
					$query = $query->onlyTrashed();
					if (!empty(request('search')['value'])) {
						$query = $query->where(function ($q) {
							$q->where('name', 'like', '%' . request('search')['value'] . '%')
								->orWhere('id', 'like', '%' . request('search')['value'] . '%')
								->orWhere('status', 'like', '%' . request('search')['value'] . '%')
								->orWhere('expiry_date', 'like', '%' . request('search')['value'] . '%')
								->orWhere('created_at', 'like', '%' . request('search')['value'] . '%');
						});
					}
				}
			}

			return \DataTables::of($query)
				->addColumn('action', function ($item) {
					return (string) view('quotations._actions', compact('item'));
				})
				->addColumn('studentname', function ($item) {
					if ($item->user_id) {
						return $item->student->full_name;
					}
				})
				->addColumn('Owner', function ($item) {
					if ($item->owner) {
						return $item->Owner->full_name;
					}
				})
                ->editColumn('expiry_date', function ($app) {
                    if ($app->status == \APPLICATIONFORM_STATUSES::DRAFT) {
                        return Carbon::createFromFormat('d/m/y', $app->expiry_date)->format('d-m-Y');
                    } else {
                        return '';
                    }
                })
                ->editColumn('created_at', function ($item) {

                    return Carbon::createFromFormat('d/m/y', $item->created_at)->format('d-m-Y');

                })
                ->editColumn('updated_at', function ($app) {
                    return Carbon::createFromFormat('d/m/y', $app->updated_at)->format('d-m-Y');
                })
                ->filter(function ($query) {

				$search = request()->get('search');
				$search = $search['value'];
				if (trim($search) != '') {
					$query->where(function ($query) use ($search) {
						$query->orWhere('id', 'like', '%' . $search . '%');
						$query->orWhere('name', 'like', '%' . $search . '%');
						$query->orWhereHas('student', function ($query2) use ($search) {
							$query2->whereRaw("concat(first_name, ' ', last_name) like '%" . $search . "%'");
						});
						$query->orWhereHas('owner', function ($query2) use ($search) {
							$query2->whereRaw("concat(first_name, ' ', last_name) like '%" . $search . "%'");
						});

					});

				}

			})

				->make(true);
		}

	}

	public function getOshcAttribute() {

		if ($this->healths->count() > 0) {
			$oshc = $this->healths[0];
			return trim($oshc->pivot->cover) . ' - ' . trim($oshc->pivot->length) . ' ' . trim($oshc->unit->name);
		} else {
			return trans('applicationform.step3.section4.no_oshc');
		}
	}

	public function getIsStudentVisaAttribute() {
		return $this->visas->is_student_visa;
	}

	public function getHasHomestayAccommodationAttribute() {
		$homestayCat = \App\Browns\Models\Accommodation\Category::where('name', 'Homestay')->first();

		if ($homestayCat) {

			$quotation = Quotation::
				with(['accommodations' => function ($query) use ($homestayCat) {
				return $query->where('category_id', $homestayCat->id);
			}])
				->where('id', $this->id)
				->first();

			if ($quotation) {
				if ($quotation->accommodations) {
					if ($quotation->accommodations->isEmpty()) {
						$hasHomeStayAccommodation = false;
					} else {
						$hasHomeStayAccommodation = true;
					}
				} else {
					$hasHomeStayAccommodation = false;
				}

				return $hasHomeStayAccommodation;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	private function allTeamMemberids() {
		$allchildrenIDs = array();
		$this->getChildrenIDs(auth()->user(), $allchildrenIDs);
		$pos = array_search(Auth::user()->id, $allchildrenIDs);
		unset($allchildrenIDs[$pos]);
		return $allchildrenIDs;
	}

	private function getChildrenIDs($userobj, &$myarr) {
		foreach ($userobj->allChildrenAccounts as $child) {
			$this->getChildrenIDs($child, $myarr);
		}
		array_push($myarr, $userobj->id);
	}

}
