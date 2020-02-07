<?php
/**
 * Created by PhpStorm.
 * User: AHSAN
 * Date: 05/12/17
 * Time: 3:50 PM
 */

namespace App\Browns\Services;
use App\Browns\Models\Accommodation;
use App\Browns\Models\AccommodationSpecialOffer;
use App\Browns\Models\Accommodation\Addon as AccommodationAddon;
use App\Browns\Models\Accommodation\Preference as AccommodationPreference;
use App\Browns\Models\Accommodation\PriceBook as AccommodationPricebook;
use App\Browns\Models\Accommodation\Service as AccommodationService;
use App\Browns\Models\Apartment\Unit;
use App\Browns\Models\Calender;
use App\Browns\Models\Country;
use App\Browns\Models\Exam;
use App\Browns\Models\FeeExperience;
use App\Browns\Models\FeeGeneralAddon;
use App\Browns\Models\FeeInternshipService;
use App\Browns\Models\FeeService;
use App\Browns\Models\Internship;
use App\Browns\Models\OSHCFee;
use App\Browns\Models\PriceBook;
use App\Browns\Models\Program;
use App\Browns\Models\QuotationServiceInstallment;
use App\Browns\Models\SpecialOffer;
use App\Browns\Models\StudyUnit;
use App\Browns\Models\TransportationAddon;
use App\Browns\Models\TransportationType;
use App\Browns\Models\User;
use App\Browns\Models\Campus;
use App\Browns\Models\TuitionProtectionService;
use App\Browns\Models\Visa;
use App\Browns\Models\QuotationInstallment;
use Illuminate\Support\Facades\Auth;
use \Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CalculatorService {

	/*******  Program Calculation Section ********/
	public function singleProgramFeeCalculation($program) {
		$myprogram = Program::findorfail($program['id']);
		$courselength = intval($program['length']);
		$mypricebook = $myprogram->priceBook;
		$price = $this->priceBookCalculation($mypricebook, $courselength);
		$program['price'] = $price;
		return $program;
	}

	public function tieredArrayConversion($programs) {
		$tiered_arr = array();
		foreach ($programs as $program) {
			$myprogram = Program::findorfail($program['id']);
			if ($myprogram->tier_count == 1) {
				if (array_key_exists($myprogram->priceBook->studyunit->name, $tiered_arr)) {
					array_push($tiered_arr[$myprogram->priceBook->studyunit->name], $program);
				} else {
					$tiered_arr[$myprogram->priceBook->studyunit->name] = array();
					array_push($tiered_arr[$myprogram->priceBook->studyunit->name], $program);
				}
			} else {
				if (array_key_exists($myprogram->name, $tiered_arr)) {
					array_push($tiered_arr[$myprogram->name], $program);
				} else {
					$tiered_arr[$myprogram->name] = array();
					array_push($tiered_arr[$myprogram->name], $program);
				}
			}
		}
		return $tiered_arr;
	}

	public function tieredProgramFeeCalculation($programs) {
		$final_arr = array();
		$tiered_arr = $this->tieredArrayConversion($programs);

		foreach ($tiered_arr as $tier) {
			$totallength = 0;
			foreach ($tier as $prog) {
				$totallength = $totallength + intval($prog['length']);
			}

			foreach ($tier as $prog) {
				$myprogram = Program::findorfail($prog['id']);
				$mypricebook = $myprogram->priceBook;
				$courselength = intval($prog['length']);
				$price = $this->priceBookCalculation($mypricebook, $courselength, $totallength);
				$prog['price'] = $price;
				array_push($final_arr, $prog);
			}
		}

		return $final_arr;
	}

	public function priceBookCalculation($pricebook, $length, $totallength = NULL) {
		$price = 0;
		if ($totallength == NULL) {
			$length_comparitor = $length;
		} else {
			$length_comparitor = $totallength;
		}
		if ($pricebook->charge_in_tiers == false) {
			foreach ($pricebook->details as $entry) {
				if ($length_comparitor >= $entry->min && $length_comparitor <= $entry->max) {
					$price = floatval($entry->price * $length);
					break;
				}
				else {
					$price = floatval($entry->price * $length);
				}
			}
		} else {
			$counter = $length;
			foreach ($pricebook->details as $entry) {
				if ($counter > 0) {
					if ($length_comparitor >= $entry->min || $length_comparitor <= $entry->max) {
						if ($counter > $entry->max) {
							$counter = $counter - $entry->max;
							$price = $price + floatval($entry->max * $entry->price);
						} else {
							$price = $price + ($counter * floatval($entry->price));
							$counter = 0;
						}
					}
				}
			}
		}
		return round($price);
	}
	/*******  Program Calculation Section ends ********/

	/*******  Service Calculation Section ********/
	public function programServicesFeeCalculation($services) {
		$final_arr = array();
		foreach ($services as $service) {
			$price = $this->singleServiceFeeCalculation($service['id'], $service['length']);
			$service['price'] = $price;
			array_push($final_arr, $service);
		}
		return $final_arr;
	}

	public function singleServiceFeeCalculation($id, $length) {
		$service = FeeService::findorfail($id);
		if ($service->feetype->name == 'Per Unit') {
			$price = floatval($service->fee) * $length;
		} else {
			$price = floatval($service->fee);
		}
		return $price;
	}

	public function ServiceFeeCalculationWithInsertion($services, $length, $currentservices) {
		$final_arr = array();
		foreach ($services as $myservice) {
			$id = $myservice['id'];
			$service = FeeService::findorfail($id);
			$returned_obj = array();
			if ($service->feetype->name == "Per Application") {
				$result = array_filter($currentservices, function ($item) use ($id) {
					if ($item['id'] == $id) {
						return $item;
					}
				});
				if (sizeof($result) == 0) {
					$returned_obj['price'] = floatval($service->fee);
					$returned_obj['is_insert'] = true;
				} else {
					$returned_obj['is_insert'] = false;
				}
			} else if ($service->feetype->name == "Per Unit") {
				$returned_obj['price'] = floatval($service->fee) * $length;
				$returned_obj['is_insert'] = true;
			} else if ($service->feetype->name == "Per Student") {
				$result1 = array_filter($currentservices, function ($item) use ($id) {
					if ($item['id'] == $id) {
						return $item;
					}
				});

				$user_services_arr = array();
				$user = User::findorfail(Auth::id());
				$quotes = $user->quotations;
				foreach ($quotes as $quote) {
					foreach ($quote->services as $qservice) {
						array_push($user_services_arr, $qservice);
					}
				}
				$result2 = array_filter($user_services_arr, function ($item) use ($id) {
					if ($item->id == $id) {
						return $item;
					}
				});

				if (sizeof($result1) == 0 && sizeof($result2) == 0) {
					$returned_obj['price'] = floatval($service->fee);
					$returned_obj['is_insert'] = true;
				} else {
					$returned_obj['is_insert'] = false;
				}
			} else {
				$returned_obj['price'] = floatval($service->fee);
				$returned_obj['is_insert'] = true;
			}
			if ($returned_obj['is_insert'] == true) {
				array_push($currentservices, $myservice);
				$myservice['price'] = $returned_obj['price'];
			}
			$myservice['is_insert'] = $returned_obj['is_insert'];
			array_push($final_arr, $myservice);
		}

		return $final_arr;

	}

	public function perAppServiceCheck($programs, $services) {
		$returned_arr = array();
		foreach ($programs as $program) {
			$programobj = Program::findorfail($program['id']);
			$pservices = $programobj->saveServices;
			foreach ($pservices as $service) {
				if ($service->feetype->name == "Per Student" || $service->feetype->name == "Per Application") {
					$id = $service->id;
					if ($service->feetype->name == "Per Application") {
						$result = array_filter($services, function ($item) use ($id) {
							if ($item['id'] == $id) {
								return $item;
							}
						});
						if (sizeof($result) == 0 && $service->pivot->is_mandatory==1) {
							$robj['price'] = floatval($service->fee);
							$robj['is_insert'] = true;
						} else {
							$robj['is_insert'] = false;
						}
					} else if ($service->feetype->name == "Per Student") {
						$result1 = array_filter($services, function ($item) use ($id) {
							if ($item['id'] == $id) {
								return $item;
							}
						});

						$user_services_arr = array();
						$user = User::findorfail(Auth::id());
						$quotes = $user->quotations;
						foreach ($quotes as $quote) {
							foreach ($quote->services as $qservice) {
								array_push($user_services_arr, $qservice);
							}
						}
						$result2 = array_filter($user_services_arr, function ($item) use ($id) {
							if ($item->id == $id) {
								return $item;
							}
						});

						if (sizeof($result1) == 0 && sizeof($result2) == 0) {
							$robj['price'] = floatval($service->fee);
							$robj['is_insert'] = true;
						} else {
							$robj['is_insert'] = false;
						}
					}
					if ($robj['is_insert'] == true) {
						$myarr = array(
							'id' => $service->id,
							'name' => $service->name,
							'price' => $robj['price'],
							'discount_price' => null,
							'parent_id' => $program['parent_id'],
							'program_id' => $program['id'],
							'length' => $program['length'],
							'type' => 'service',
                            'tax'=> $service->quoteTax,
                            'self' => $service
						);
						array_push($returned_arr, $myarr);
						array_push($services, $myarr);
					}
				}

			}
		}
		return $returned_arr;
	}
	/*******  Service Calculation Section ends ********/

	/*******  Exam Calculation Section ********/
	public function programExamsFeeCalculation($exams) {
		$final_arr = array();
		foreach ($exams as $exam) {
			$price = $this->singleExamFeeCalculation($exam['id']);
			$exam['price'] = $price;
			array_push($final_arr, $exam);
		}
		return $final_arr;
	}

	public function singleExamFeeCalculation($id) {
		$exam = Exam::findorfail($id);
		$price = floatval($exam->fee);
		return $price;
	}
	/*******  Exam Calculation Section ends********/

	/*******  Addon Calculation Section********/
	public function addonsFeeCalculation($addons) {
		$final_arr = array();
		foreach ($addons as $addon) {
			$price = $this->singleAddonFeeCalculation($addon['id']);
			$addon['price'] = $price;
			array_push($final_arr, $addon);
		}
		return $final_arr;
	}

	public function singleAddonFeeCalculation($id) {
		$addon = FeeGeneralAddon::findorfail($id);
		$price = floatval($addon->fee);
		return $price;
	}
	/*******  Addon Calculation Section ends********/

	/*******  Experience Calculation Section********/

	public function experiencesFeeCalculation($experiences) {
		$final_arr = array();
		foreach ($experiences as $experience) {
			$price = $this->singleExperienceFeeCalculation($experience['id']);
			$experience['price'] = $price;
			array_push($final_arr, $experience);
		}
		return $final_arr;
	}

	public function singleExperienceFeeCalculation($id) {
		$experience = FeeExperience::findorfail($id);
		$price = floatval($experience->fee);
		return $price;
	}
	/*******  Experience Calculation Section ends********/

	/*******  Health Calculation Section********/
	public function healthFeeCalculation($healths) {
		$final_arr = array();
		foreach ($healths as $health) {
			$price = $this->singleHealthFeeCalculation($health['id'], $health['length'], $health['cover']);
			$health['price'] = $price;
			array_push($final_arr, $health);
		}
		return $final_arr;
	}

	public function singleHealthFeeCalculation($id, $length, $cover) {
		$health = OSHCFee::findorfail($id);
		$cover = str_replace(' ', '', $cover);
		$details = $health->details->toArray();
		for ($i = 0; $i < sizeof($details); $i++) {
			if ($details[$i]['value'] == $length) {
				break;
			}
		}
		if ($cover == "Single") {
			$step = 1;
		} elseif ($cover == "Couples") {
			$step = 2;
		} else {
			$step = 3;
		}
		$j = $i + $step;
		$price = $details[$j]['value'];
		return floatval($price);
	}
	/*******  Health Calculation Section ends********/

	/*******  Internship Calculation Section********/
	public function internshipFeeCalculation($internships) {
		$final_arr = array();
		foreach ($internships as $internship) {
			$price = $this->singleInternshipFeeCalculation($internship['id'], $internship['length']);
			$internship['price'] = $price;
			$services_arr = array();
			foreach ($internship['services'] as $service) {
				$price = $this->internshipServiceFeeCalculation($service['id']);
				$service['price'] = $price;
				array_push($services_arr, $service);
			}
			$internship['services'] = $services_arr;
			array_push($final_arr, $internship);
		}
		return $final_arr;
	}

	public function singleInternshipFeeCalculation($id, $length) {
		$internship = Internship::findorfail($id);
		$details = $internship->details;
		$price = 0;
		foreach ($details as $detail) {
			if ($length >= $detail->min && $length <= $detail->max) {
				$price = floatval($detail->fee);
			}
		}
		return $price;
	}

	public function internshipServiceFeeCalculation($id) {
		$service = FeeInternshipService::findorfail($id);
		return floatval($service->fee);
	}

	public function getInternshipServices($id) {
		$internship = Internship::findorfail($id);
		$services = array();
		$all_services = $internship->feeinternshipservices;
		foreach ($all_services as $service) {
			$myservice = array();
			$myservice['id'] = $service->id;
			$myservice['name'] = $service->name;
			$myservice['price'] = $service->fee;
			array_push($services, $myservice);
		}
		return $services;
	}
	/*******  Internship Calculation Section ends********/

	/*******  Accommodation Calculation Section********/
	public function accommodationFeeCalculation($accommodations) {
		$final_arr = array();
		foreach ($accommodations as $accommodation) {
			$myaccommodation = $this->singleAccommodationFeeCalculation($accommodation);
			array_push($final_arr, $myaccommodation);
		}
		return $final_arr;
	}

	public function singleAccommodationFeeCalculation($accommodation) {
		$myaccommodation = Accommodation::findorfail($accommodation['id']);
		$pricebook = $myaccommodation->priceBook;
		$unit_arr = $this->getUnitandRemDays($accommodation['checkin'], $accommodation['checkout'], $pricebook->unit_id);
		$unitval = $unit_arr['unitval'];
		$unitno = $unit_arr['unitno'];
		$unitrem = $unit_arr['unitrem'];
		$conditionallength = ceil($unit_arr['days'] / $unitval);
		$price = $this->accommodationPricebookCalculation($pricebook, $conditionallength, $unitno, $unitrem, $unitval);
		$accommodation['price'] = $price;
		$accommodation['unitno'] = $unitno;
		$accommodation['unitrem'] = $unitrem;
		return $accommodation;
	}

	public function accommodationPricebookCalculation($pricebook, $length, $unit, $rem, $unitval) {
		$price = 0;
		if ($pricebook->charge_in_tiers == false) {
			// charge in tier price calculation
			foreach ($pricebook->details as $entry) {
				if ($length >= $entry->min && $length <= $entry->max) {
					$weekprice = floatval($entry->price) * floatval($unit);
					if ($pricebook->round == 'Round_Up_Unit_Type') {
						$unitvalcal = ceil((floatval($entry->price) / $unitval));
					} else if ($pricebook->round == 'Round_Down_Unit_Type') {
						$unitvalcal = floor((floatval($entry->price) / $unitval));
					} else {
						$unitvalcal = floatval($entry->price) / $unitval;
					}
					$dayprice = $unitvalcal * intval($rem);
					$price = $weekprice + $dayprice;
					break;
				}
				else {
					$weekprice = floatval($entry->price) * floatval($unit);
					if ($pricebook->round == 'Round_Up_Unit_Type') {
						$unitvalcal = ceil((floatval($entry->price) / $unitval));
					} else if ($pricebook->round == 'Round_Down_Unit_Type') {
						$unitvalcal = floor((floatval($entry->price) / $unitval));
					} else {
						$unitvalcal = floatval($entry->price) / $unitval;
					}
					$dayprice = $unitvalcal * intval($rem);
					$price = $weekprice + $dayprice;
				}
			}
		} else {
			$counter = $unit;
			foreach ($pricebook->details as $entry) {
				if ($counter > 0) {
					if ($length >= $entry->min || $length <= $entry->max) {
						if ($counter > $entry->max) {
							$counter = $counter - $entry->max;
							$weekprice = floatval($entry->price) * intval($entry->max);
							$price = $price + $weekprice;
						} else {
							$weekprice = floatval($entry->price) * intval($counter);
							if ($pricebook->round == 'Round_Up_Unit_Type') {
								$unitvalcal = ceil((floatval($entry->price) / $unitval));
							} else if ($pricebook->round == 'Round_Down_Unit_Type') {
								$unitvalcal = floor((floatval($entry->price) / $unitval));
							} else {
								$unitvalcal = floatval($entry->price) / $unitval;
							}
							$dayprice = $unitvalcal * intval($rem);
							$price = $price + $weekprice + $dayprice;
							$counter = 0;
						}
					}
				}
			}
		}
		return $price;
	}

	public function getUnitandRemDays($checkin, $checkout, $unitid) {
		$checkintime = Carbon::parse($checkin);
		$checkouttime = Carbon::parse($checkout);
		$difference = $checkouttime->diffInDays($checkintime);
		$unit = StudyUnit::findorfail($unitid);
		$unitval = $unit->value;
		$unitno = intval($difference/$unitval);
		$unitrem = $difference - ($unitno * $unitval);
		$returned_arr = array('unitno' => $unitno, 'unitrem' => $unitrem, 'unitval' => $unitval, 'days' => $difference);
		return $returned_arr;
	}
	/*******  Accommodation Calculation Section ends********/

	/*******  Accommodation Service Calculation Section********/
	public function accommodationServicesFeeCalculation($services) {
		$final_arr = array();
		foreach ($services as $service) {
			$price = $this->singleAccommodationServiceCalculation($service['id'], $service['checkin'], $service['checkout'], $service['accommodation_id']);
			$service['price'] = $price;
			array_push($final_arr, $service);
		}
		return $final_arr;
	}

	public function singleAccommodationServiceCalculation($id, $checkin, $checkout, $acc_id) {

		$service = AccommodationService::findorfail($id);
		$myaccommodation = Accommodation::findorfail($acc_id);
		$pricebook = $myaccommodation->priceBook;
		$unit_arr = $this->getUnitandRemDays($checkin, $checkout, $pricebook->unit_id);
		$unitval = $unit_arr['unitval'];
		$unitno = $unit_arr['unitno'];
		$unitrem = $unit_arr['unitrem'];
        $unitremval = 1;
		$converted_length = 0;
		if ($unitval) {
			$converted_length = $unitno;
            $unitremval = $unitrem/$unitval;
		}

		if ($service->feetype->name == 'Per Unit') {
			if ($unitval) {
				$price = floatval($service->fee) * $converted_length;
				$price = $price + ($service->fee * $unitremval);
			} else {
				$price = floatval($service->fee);
			}
		} else {
			$price = floatval($service->fee);
		}
		return $price;

	}

	public function accommodationServiceFeeCalculationWithInsertion($services, $checkin, $checkout, $acc_id, $currentservices) {
		$final_arr = array();

		foreach ($services as $myservice) {
			$id = $myservice['id'];
			$service = AccommodationService::findorfail($id);
			$returned_obj = array();
			if ($service->feetype->name == "Per Application") {
				$result = array_filter($currentservices, function ($item) use ($id) {
					if ($item['id'] == $id) {
						return $item;
					}
				});
				if (sizeof($result) == 0) {
					$returned_obj['price'] = floatval($service->fee);
					$returned_obj['is_insert'] = true;
				} else {
					$returned_obj['is_insert'] = false;
				}
			} else if ($service->feetype->name == "Per Unit") {
				$accommodation = Accommodation::findorfail($acc_id);
				$pricebook = $accommodation->priceBook;
				$unit_arr = $this->getUnitandRemDays($checkin, $checkout, $pricebook->unit_id);
				$unitval = $unit_arr['unitval'];
				$unitno = $unit_arr['unitno'];
				$unitrem = $unit_arr['unitrem'];
				if ($unitval) {
					$price = floatval($service->fee) * $unitno;
					$price = $price + ($service->fee * floatval($unitrem/$unitval));

				} else {
					$price = floatval($service->fee);
				}
				$returned_obj['price'] = $price;
				$returned_obj['is_insert'] = true;
			} else if ($service->feetype->name == "Per Student") {
				$result1 = array_filter($currentservices, function ($item) use ($id) {
					if ($item['id'] == $id) {
						return $item;
					}
				});

				$user_services_arr = array();
				$user = User::findorfail(Auth::id());
				$quotes = $user->quotations;
				foreach ($quotes as $quote) {
					foreach ($quote->accommodationServices as $service) {
						array_push($user_services_arr, $service);
					}
				}
				$result2 = array_filter($user_services_arr, function ($item) use ($id) {
					if ($item->id == $id) {
						return $item;
					}
				});

				if (sizeof($result1) == 0 && sizeof($result2) == 0) {
					$returned_obj['price'] = floatval($service->fee);
					$returned_obj['is_insert'] = true;
				} else {
					$returned_obj['is_insert'] = false;
				}
			} else {
				$returned_obj['price'] = floatval($service->fee);
				$returned_obj['is_insert'] = true;
			}
			if ($returned_obj['is_insert'] == true) {
				array_push($currentservices, $service);
				$service['price'] = $returned_obj['price'];
			}

			$service['is_insert'] = $returned_obj['is_insert'];
			array_push($final_arr, $service);
		}
		return $final_arr;
	}

	public function accommodationAppServiceCheck($accommodations, $services) {
		$returned_arr = array();
		foreach ($accommodations as $accommodation) {
			$accommodationobj = Accommodation::findorfail($accommodation['id']);
			$aservices = $accommodationobj->saveServices;
			foreach ($aservices as $service) {
				if ($service->feetype->name == "Per Student" || $service->feetype->name == "Per Application") {
					$id = $service->id;
					if ($service->feetype->name == "Per Application") {
						$result = array_filter($services, function ($item) use ($id) {
							if ($item['id'] == $id) {
								return $item;
							}
						});
						if (sizeof($result) == 0 && $service->pivot->is_mandatory==1) {
							$robj['price'] = floatval($service->fee);
							$robj['is_insert'] = true;
						} else {
							$robj['is_insert'] = false;
						}
					} else if ($service->feetype->name == "Per Student") {
						$result1 = array_filter($services, function ($item) use ($id) {
							if ($item['id'] == $id) {
								return $item;
							}
						});

						$user_services_arr = array();
						$user = User::findorfail(Auth::id());
						$quotes = $user->quotations;
						foreach ($quotes as $quote) {
							foreach ($quote->accommodationServices as $qservice) {
								array_push($user_services_arr, $qservice);
							}
						}
						$result2 = array_filter($user_services_arr, function ($item) use ($id) {
							if ($item->id == $id) {
								return $item;
							}
						});

						if (sizeof($result1) == 0 && sizeof($result2) == 0) {
							$robj['price'] = floatval($service->fee);
							$robj['is_insert'] = true;
						} else {
							$robj['is_insert'] = false;
						}
					}
					if ($robj['is_insert'] == true) {
						$myarr = array(
							'id' => $service->id,
							'name' => $service->name,
							'price' => $robj['price'],
							'discount_price' => null,
							'parent_id' => $accommodation['parent_id'],
							'accommodation_id' => $accommodation['id'],
							'checkin' => $accommodation['checkin'],
							'checkout' => $accommodation['checkout'],
							'type' => 'accommodationservices',
                            'tax' => $service->quoteTax,
                            'self' => $service
						);
						array_push($returned_arr, $myarr);
						array_push($services, $myarr);
					}
				}

			}
		}
		return $returned_arr;
	}
	/*******  Accommodation Service Calculation Section ends********/

	/*******  Accommodation Addon Calculation Section ********/
	public function accommodationAddonsFeeCalculation($addons, $programs) {
		$final_arr = array();
		$plength = 0;
		foreach($programs as $program){
			$plength = $plength + intval($program['length']);
		}

		foreach ($addons as $addon) {
			$price = $this->singleAccommodationAddonCalculation($addon['id'], $addon['checkin'], $addon['checkout'], $addon['accommodation_id'], $plength);
			$addon['price'] = $price;
			$addon['plength'] = $plength;
			array_push($final_arr, $addon);
		}
		return $final_arr;
	}

	public function singleAccommodationAddonCalculation($id, $checkin, $checkout, $acc_id, $plength, $special_billing=NULL) {
		$addon = AccommodationAddon::findorfail($id);
		$myaccommodation = Accommodation::findorfail($acc_id);
		$pricebook = $myaccommodation->priceBook;
		$unit_arr = $this->getUnitandRemDays($checkin, $checkout, $pricebook->unit_id);
		if(is_null($special_billing)){
            if($addon->special_billing==true){
                $checkoutobj = Carbon::parse($checkout);
                $special_dateobj = Carbon::parse($myaccommodation->special_billing_date);
                if($checkoutobj>$special_dateobj) {
                    $myprice = $addon->billing_fee;
                }
                else {
                    $myprice = $addon->fee;
                }
            }
            else {
                $myprice = $addon->fee;
            }
        }
        else {
            $myprice = $addon->billing_fee;
        }


		if($addon->addon_charge=='pr_length') {
			$unitprice = floatval($plength * $addon->fee);
			$unitvalcalculate = 0;
		}
		else if($addon->addon_charge=='ac_length') {
			$unitprice = floatval($unit_arr['unitno'] * $myprice);
			$unitvalcalculate = floatval($myprice / $unit_arr['unitval']);
		}
		else {

			if($plength > intval($unit_arr['unitno'])) {
				$unitprice = floatval($unit_arr['unitno'] * $myprice);
				$unitvalcalculate = floatval($myprice / $unit_arr['unitval']);
			}
			else {
				$unitprice = floatval($plength * $addon->fee);
				$unitvalcalculate = 0;
			}

		}


		if ($addon->round == 'Round_Up_Unit_Type') {
			$unitvalcal = ceil($unitvalcalculate);
		} else if ($addon->round == 'Round_Down_Unit_Type') {
			$unitvalcal = floor($unitvalcalculate);
		} else {
			$unitvalcal = $unitvalcalculate;
		}
		$dayprice = floatval($unit_arr['unitrem'] * $unitvalcal);
		$price = $unitprice + $dayprice;
		//return number_format($price, 2);
		return $price;
	}
	/*******  Accommodation Addon Calculation Section ends********/

	/*******  Accommodation Preference Calculation Section ********/
	public function accommodationPreferencesFeeCalculation($preferences) {
		$final_arr = array();
		foreach ($preferences as $preference) {
			$price = $this->singleAccommodationPreferenceCalculation($preference['id'], $preference['checkin'], $preference['checkout'], $preference['accommodation_id']);
			$preference['price'] = $price;
			array_push($final_arr, $preference);
		}
		return $final_arr;
	}

	public function singleAccommodationPreferenceCalculation($id, $checkin, $checkout, $acc_id, $special_billing=NULL) {
		$preference = AccommodationPreference::findorfail($id);
		$myaccommodation = Accommodation::findorfail($acc_id);
		$pricebook = $myaccommodation->priceBook;
		$unit_arr = $this->getUnitandRemDays($checkin, $checkout, $pricebook->unit_id);
		if($special_billing==null){
		    if($preference->special_billing==true){
                $checkoutobj = Carbon::parse($checkout);
                $special_dateobj = Carbon::parse($myaccommodation->special_billing_date);
                if($checkoutobj>$special_dateobj) {
                    $myprice = $preference->billing_fee;
                }
                else {
                    $myprice = $preference->fee;
                }
            }
            else {
                $myprice = $preference->fee;
            }

        }
        else {
            $myprice = $preference->billing_fee;
        }

        $unitprice = floatval($unit_arr['unitno'] * $myprice);
        $unitvalcalculate = floatval($myprice / $unit_arr['unitval']);

		if ($preference->round == 'Round_Up_Unit_Type') {
			$unitvalcal = ceil($unitvalcalculate);
		} else if ($preference->round == 'Round_Down_Unit_Type') {
			$unitvalcal = floor($unitvalcalculate);
		} else {
			$unitvalcal = $unitvalcalculate;
		}
		$dayprice = floatval($unit_arr['unitrem'] * $unitvalcal);
		$price = $unitprice + $dayprice;
		//return number_format($price, 2);
		return $price;
	}
	/*******  Accommodation Preference Calculation Section ends********/

	/*******  Transportation Calculation Section********/

	public function transportFeeCalculation($transports) {
		$final_arr = array();
		foreach ($transports as $transport) {
			$price = $this->singleTansportFeeCalculation($transport['id'], $transport['campus_from'], $transport['campus_to']);
			$transport['price'] = $price;
			array_push($final_arr, $transport);
		}
		return $final_arr;
	}

	public function singleTansportFeeCalculation($id, $campus_from, $campus_to) {

		$transport = TransportationType::findorfail($id);
		$price = 0;
		$destinations_size = sizeof($transport->saveTestlevels);

		for ($i = 0; $i < $destinations_size; $i++) {
			$campusTo = Campus::findorfail($transport->saveTestlevels[$i]->pivot->campus_to);
			if ($transport->saveTestlevels[$i]->name == $campus_from && $campusTo->name == $campus_to) {
				$price = $transport->saveTestlevels[$i]->pivot->fee;
				break;
			}
		}

		return floatval($price);
	}


	public function mandatoryTransportCheck($accommodations, $transports, $trans_items){
	    foreach ($accommodations as $accommodation) {
	        $myaccommodation = Accommodation::find($accommodation['id']);
	        foreach($myaccommodation->saveTransportations as $checktrans){
                if($checktrans['pivot']['is_mandatory']==true) {
                    $result1 = array_filter($transports,function($item) use ($checktrans){
                        if($item['id']==$checktrans['id']){
                            return true;
                        }
                    });
                    if(sizeof($result1) == 0) {
                        $transes = $trans_items['transes'];
                        $result2 = array_filter($transes,function($item) use ($checktrans){
                            if($item['id']==$checktrans['id']){
                                return true;
                            }
                        });
                        if(sizeof($result2) > 0) {
                            $result2 = end($result2);
                            $result2['parent_id'] = $accommodation['parent_id'];
                            $result2['accommodation_id'] = $accommodation['id'];
                            array_push($transports, $result2);
                            foreach ($trans_items['transaddons'] as $key=>$addon) {
                                $addon[$key]['parent_id'] = $accommodation['parent_id'];
                            }
                            for($i=0;$i<sizeof($trans_items['transaddons']);$i++){
                                $trans_items['transaddons'][$i]['parent_id'] = $accommodation['parent_id'];
                            }
                        }
                    }
                }
            }
        }
        $returned_obj = array();
	    $returned_obj['trans'] = $transports;
	    $returned_obj['transaddons'] = $trans_items['transaddons'];
	    return $returned_obj;
    }
	/*******  Transportation Calculation Section ends********/

	/*******  Transportation Addon Calculation Section********/
	public function transportAddonFeeCalculation($addons) {
		$final_arr = array();
		foreach ($addons as $addon) {
			$price = $this->singleTansportAddonFeeCalculation($addon['id']);
			$addon['price'] = $price;
			array_push($final_arr, $addon);
		}
		return $final_arr;
	}

	public function singleTansportAddonFeeCalculation($id) {
		$addon = TransportationAddon::findorfail($id);
		return floatval($addon->fee);
	}
	/*******  Transportation Addon Calculation Section ends********/

	/*******  Program Special Offers Section   ***********/
	public function getProgramSpecialOffers($criteria) {
		$visa = $criteria['visa'];
		$country = $criteria['country'];
		$age = $criteria['ageStatus'];
		$regionid = 0;
		if($country['id']=='other') {
			$countryobj = null;
		}
		else {
			$countryobj = Country::find($country['id']);
			if($countryobj && isset($countryobj->region)){
                $regionid = $countryobj->region->id;
            }
		}

		$today = Carbon::today();

		$specialoffers = SpecialOffer::whereDate('booking_to', '>=', $today)->where('is_activated', 1)->whereHas('visas', function ($query) use ($visa) {
			$query->where('id', $visa['id']);
		})->whereHas('region', function ($q) use ($regionid) {
			$q->where('id', $regionid);
		})->get();

		return $specialoffers;
	}

	public function getOffPriceDiscount($item, $length = NULL, $special_billing=NULL, $accommodation=NULL) {
	    if($special_billing){
            $mycheckout = Carbon::parse($special_billing['checkout']);
            $special_date = Carbon::parse($accommodation['self']['special_billing_date']);
            if($item['special_billing']==true && $accommodation['self']['special_billing']==true && $mycheckout>$special_date) {
                $myprice = $item['billing_fee'];
            }
            else {
                $myprice = $item['fee'];
            }

        }
        else {
	        $myprice = $item['fee'];
        }

		if ($item['pivot']['type'] == 'value_override') {
			$overrrideprice = $item['pivot']['value'];
		} else if ($item['pivot']['type'] == 'amount_off') {
			$overrrideprice = intval($myprice) - intval($item['pivot']['value']);
		} else {
			$after_off = 100 - intval($item['pivot']['value']);
			$overrrideprice = ($after_off / 100) * intval($myprice);
		}

		if ($length) {
			if ($item['feetype']['name'] == 'Per Unit') {
				$overrrideprice = $length * $overrrideprice;
			}
		}
		return $overrrideprice;
	}

	public function programSpecialOfferDetection($programs, $services, $exams, $specialoffers, $offer_expire_date, $ownerid=NULL) {
		$tiered_arr = $this->tieredArrayConversion($programs);
		$final_arr = array();
		$prog_arr = array();
		$expire_date = null;
		$applied_offers = array();
		$totallength = 0;
		foreach ($tiered_arr as $tier) {
			$totallength = 0;
			$earliest_date = Carbon::parse($tier[0]['start_date']);
			foreach ($tier as $prog) {
				// for length and earliest date calculations
				$totallength = $totallength + intval($prog['length']);
				$testdate = Carbon::parse($prog['start_date']);
				if ($testdate < $earliest_date) {
					$earliest_date = $testdate;
				}
			}

			foreach ($tier as $prog) {
				// for special offer detection and calculations
				$prog['discount_price'] = null;
				$prog['discount_pricebook'] = null;
				for ($i = 0; $i < sizeof($services); $i++) {
					if ($services[$i]['parent_id'] == $prog['parent_id']) {
						$services[$i]['discount_price'] = null;
					}
				}
				for ($i = 0; $i < sizeof($exams); $i++) {
					if ($exams[$i]['parent_id'] == $prog['parent_id']) {
						$exams[$i]['discount_price'] = null;
					}
				}

				$result = $this->getAppliedOffer($prog, $specialoffers, $totallength, $earliest_date, $applied_offers, $ownerid);
				if ($result->offer!=null) {
					$found_p = $result->program;
					$booking_to = Carbon::parse($result->offer->booking_to);
					$expire_date = $this->updateOfferExpiry($booking_to, $offer_expire_date);
					$discount = $this->programDiscountCalculation($prog, $found_p['pivot']['price_book_id'], $totallength);
					$prog['discount_price'] = $discount;
					$prog['discount_pricebook'] = $found_p['pivot']['price_book_id'];
					$services = $this->serviceDiscountCalculation($prog, $result->offer, $services,$totallength);
					$exams = $this->examDiscountCalculation($prog, $result->offer, $exams);
				}
				array_push($prog_arr, $prog);
			}
		}

		$services = $this->perAppServiceDiscountCalculation($services,$applied_offers, $totallength);

		$final_arr['programs'] = $prog_arr;
		$final_arr['services'] = $services;
		$final_arr['exams'] = $exams;
		$final_arr['expire_date'] = $expire_date;
		return $final_arr;

	}

	public function getAppliedOffer($prog, $specialoffers, $totallength, $earliest_date, &$applied_offers, $ownerid=NULL) {
		$offer_arr = array();
		$found_p = null;
		if(is_null($ownerid)){
		    $curruser = auth()->user();
        }
        else {
		    $curruser = User::find($ownerid);
        }
		foreach ($specialoffers as $offer) {
			$bookingcheck = false;
			$startcheck = false;
			$campuscheck = false;
			$unitcheck = false;
			$durationcheck = false;
			$agencycheck = false;
			$today = Carbon::today();
			$booking_from = Carbon::parse($offer->booking_from);
			$booking_to = Carbon::parse($offer->booking_to);
			$start_from = Carbon::parse($offer->start_from);
			$start_to = Carbon::parse($offer->start_to);
			$myprogram = Program::findorfail($prog['id']);
			$expire_date = null;

			if ($today >= $booking_from && $today <= $booking_to) {
				// booking date check
				$bookingcheck = true;
			}

			if ($earliest_date >= $start_from && $earliest_date <= $start_to) {
				// start date check
				$startcheck = true;
			}

			if ($offer->unit_type_id == $myprogram->priceBook->unit_id) {
				// unit check
				$unitcheck = true;
			}

			if ($totallength >= $offer->duration_min && $totallength <= $offer->duration_max) {
				// duration check
				$durationcheck = true;
			}

			$result = array_filter($offer->campuses->toArray(), function ($item) use ($prog) {
				if ($item['id'] == $prog['campus']['id']) {
					return $item;
				}
			});
			if (sizeof($result) > 0) {
				$campuscheck = true;
			}

			if($offer->agency_restriction=='apply_all') {
                $agencycheck = true;
            }
            elseif($offer->agency_restriction=='apply_certain') {
                if($curruser && $curruser->isOfType(AGENT)){
                    if(in_array($curruser->pardot_account_id, $offer->accounts->pluck('id')->toArray())) {
                        $agencycheck = true;
                    }
                }
            }
            elseif($offer->agency_restriction=='not_apply_certain') {
			    $agencycheck = true;
                if($curruser && $curruser->isOfType(AGENT)){
                    if(in_array($curruser->pardot_account_id, $offer->accounts->pluck('id')->toArray())) {
                        $agencycheck = false;
                    }
                }
            }

			if ($bookingcheck == true && $unitcheck == true && $durationcheck == true && $campuscheck == true && $startcheck == true && $agencycheck==true) {
				$program_found = array_filter($offer->programs->toArray(), function ($item) use ($prog) {
					if ($item['id'] == $prog['id']) {
						return $item;
					}
				});

				if (sizeof($program_found) > 0) {
					$found_p = reset($program_found);
					array_push($offer_arr, $offer);
					array_push($applied_offers, $offer);
				}
			}
		}

		$result = new \stdClass();
		$result->program = $found_p;
		if(sizeof($offer_arr)==0) {
			$result->offer = null;
		}
		else {
			$result->offer = $offer_arr[0];
		}

		return $result;
	}


	public function perAppServiceDiscountCalculation($services,$applied_offers, $totallength) {
		$final_arr = array();
		foreach($services as $service) {
            $service_comparator_arr = array();
			$myservice = FeeService::findorfail($service['id']);
			if($myservice->feetype->name=='Per Application'){
				foreach($applied_offers as $offer) {
					$service_found = array_filter($offer->services->toArray(), function ($item) use ($service, $totallength) {
						if ($item['id'] == $service['id']) {
							if($item['pivot']['service_length']==1) {
								if($totallength >= $item['pivot']['service_from'] && $totallength <= $item['pivot']['service_to']) {
									return $item;
								}
							}
							else {
								return $item;
							}
						}
					});


					if (sizeof($service_found) > 0) {
						$found_s = reset($service_found);
						$discount_price = $this->getOffPriceDiscount($found_s);
					}
					else {
						$discount_price = $myservice->fee;
					}
					array_push($service_comparator_arr, $discount_price);
				}
				if(!empty($service_comparator_arr)) {
					$minimum_price = min($service_comparator_arr);
					$service['discount_price'] = $minimum_price;
				}
			}
			array_push($final_arr,$service);
		}

		return $final_arr;
	}

	public function updateOfferExpiry($booking_to, $mydate) {

		if ($mydate === null) {
			$returned_date = $booking_to;
		} else {
			$current = Carbon::parse($mydate);
			if ($booking_to < $current) {
				$returned_date = $booking_to;
			} else {
				$returned_date = $current;
			}
		}
		return $returned_date->format('d-m-Y');
	}

	public function programDiscountCalculation($program, $pricebookid, $totallength) {
		$newpricebook = PriceBook::findorfail($pricebookid);
		$myprogram = Program::findorfail($program['id']);
		if ($newpricebook->is_expired == true) {
			// check for offer program pricebook expiry
			$newpricebook = $myprogram->priceBook;
		}
		$newprice = $this->priceBookCalculation($newpricebook, $program['length'], $totallength);
		return $newprice;
	}

	public function serviceDiscountCalculation($program, $offer, $services, $totallength) {
		$final_arr = array();
		foreach ($services as $service) {
			if ($service['parent_id'] == $program['parent_id']) {
				$service['discount_price'] = null;
				$service_found = array_filter($offer->services->toArray(), function ($item) use ($service, $program, $totallength) {
					if ($item['id'] == $service['id']) {
						if($item['pivot']['service_length']==1) {
							if($totallength >= $item['pivot']['service_from'] && $totallength <= $item['pivot']['service_to']) {
								return $item;
							}
						}
						else {
							return $item;
						}

					}
				});
				if (sizeof($service_found) > 0) {
					$found_s = reset($service_found);
					$discount_price = $this->getOffPriceDiscount($found_s, $program['length']);
					$service['discount_price'] = $discount_price;
				}

			}
			array_push($final_arr, $service);
		}
		return $final_arr;
	}

	public function examDiscountCalculation($program, $offer, $exams) {
		$final_arr = array();
		foreach ($exams as $exam) {
			if ($exam['parent_id'] == $program['parent_id']) {
				$exam['discount_price'] = null;
				$exam_found = array_filter($offer->exams->toArray(), function ($item) use ($exam) {
					if ($item['id'] == $exam['id']) {
						return $item;
					}
				});
				if (sizeof($exam_found) > 0) {
					$found_e = reset($exam_found);
					$discount_price = $this->getOffPriceDiscount($found_e);
					$exam['discount_price'] = $discount_price;
				}

			}
			array_push($final_arr, $exam);
		}
		return $final_arr;
	}

	/*******  Program Special Offers Section End ***********/

	/*******  Accommodation Special Offers Section ***********/

	public function getAccommodationSpecialOffers($criteria) {
		$visa = $criteria['visa'];
		$country = $criteria['country'];
		$age = $criteria['ageStatus'];
		$regionid = 0;
		if($country['id']=='other') {
			$countryobj = null;
		}
		else {
			$countryobj = Country::find($country['id']);
            if($countryobj && isset($countryobj->region)){
                $regionid = $countryobj->region->id;
            }
		}
		$today = Carbon::today();

		$accspecialoffers = AccommodationSpecialOffer::whereDate('booking_to', '>=', $today)->where('is_activated', 1)->whereHas('visas', function ($query) use ($visa) {
			$query->where('id', $visa['id']);
		})->whereHas('region', function ($q) use ($regionid) {
			$q->where('id', $regionid);
		})->get();
		return $accspecialoffers;
	}

	public function accommodationSpecialOfferDetection($accommodations, $services, $addons, $preferences, $transportations, $specialoffers, $offer_expire_date, $programs, $ownerid=NULL) {
		$accommodation_arr = array();
		$expire_date = null;
		$ptotallength = 0;
        $acc_applied_offers = array();
		foreach($programs as $program) {
			$ptotallength = $ptotallength + intval($program['length']);
		}

		foreach ($accommodations as $accommodation) {
			$accommodation['discount_price'] = null;

            for ($i = 0; $i < sizeof($services); $i++) {
                if ($services[$i]['parent_id'] == $accommodation['parent_id']) {
                    $services[$i]['discount_price'] = null;
                }
            }
            for ($i = 0; $i < sizeof($addons); $i++) {
                if ($addons[$i]['parent_id'] == $accommodation['parent_id']) {
                    $addons[$i]['discount_price'] = null;
                }
            }
            for ($i = 0; $i < sizeof($preferences); $i++) {
                if ($preferences[$i]['parent_id'] == $accommodation['parent_id']) {
                    $preferences[$i]['discount_price'] = null;
                }
            }
            for ($i = 0; $i < sizeof($transportations); $i++) {
                if ($transportations[$i]['parent_id'] == $accommodation['parent_id']) {
                    $transportations[$i]['discount_price'] = null;
                }
            }
            $myaccommodation = Accommodation::findorfail($accommodation['id']);
            $pricebook = $myaccommodation->priceBook;
            $unit_arr = $this->getUnitandRemDays($accommodation['checkin'], $accommodation['checkout'], $pricebook->unit_id);
            $result = $this->getAppliedAccommodationOffer($specialoffers, $accommodation, $ptotallength, $acc_applied_offers , $ownerid);
            if ($result->offer!=null) {
                $found_a = $result->accommodation;
                $booking_to = Carbon::parse($result->offer->booking_to);
                $expire_date = $this->updateOfferExpiry($booking_to, $offer_expire_date);
                if($found_a['pivot']['offer_type']!='keep_original') {
                    $discount = $this->accommodationDiscountCalculation($accommodation, $unit_arr, $found_a);
                    $accommodation['discount_price'] = $discount;
                }
                $services = $this->accommodationServiceDiscountCalculation($accommodation, $result->offer, $services, $unit_arr);
                $addons = $this->accommodationAddonsDiscountCalculation($accommodation, $result->offer, $addons, $unit_arr);
                $preferences = $this->accommodationPreferencesDiscountCalculation($accommodation, $result->offer, $preferences, $unit_arr);
                $transportations = $this->accommodationTransportationDiscountCalculation($accommodation, $result->offer, $transportations);
            }

			array_push($accommodation_arr, $accommodation);
		}

        $services = $this->perAppAccServiceDiscountCalculation($services,$acc_applied_offers);

		$final_arr = array();
		$final_arr['accommodations'] = $accommodation_arr;
		$final_arr['services'] = $services;
		$final_arr['addons'] = $addons;
		$final_arr['preferences'] = $preferences;
		$final_arr['transportations'] = $transportations;
		$final_arr['expire_date'] = $expire_date;
		return $final_arr;
	}

	public function getAppliedAccommodationOffer($specialoffers, $accommodation, $ptotallength, &$acc_applied_offers, $ownerid=NULL) {
        $acc_offer_arr = array();
        $found_a = null;
        if(is_null($ownerid)) {
            $myuser = auth()->user();
        }
        else {
            $myuser = User::find($ownerid);
        }
	    foreach ($specialoffers as $offer) {
            $bookingcheck = false;
            $startcheck = false;
            $campuscheck = false;
            $unitcheck = false;
            $durationcheck = false;
            $programcheck =  false;
            $agencycheck = false;
            $expire_date = null;
            $booking_from = Carbon::parse($offer->booking_from);
            $booking_to = Carbon::parse($offer->booking_to);
            $start_from = Carbon::parse($offer->start_from);
            $start_to = Carbon::parse($offer->start_to);
            $checkin = Carbon::parse($accommodation['checkin']);
            $myaccommodation = Accommodation::findorfail($accommodation['id']);
            $pricebook = $myaccommodation->priceBook;
            $unit_arr = $this->getUnitandRemDays($accommodation['checkin'], $accommodation['checkout'], $pricebook->unit_id);
            $unitno = $unit_arr['unitno'];
            $unitrem = $unit_arr['unitrem'];
            $totallength = ceil(floatval($unitno . '.' . $unitrem));
            $today = Carbon::today();

            if ($today >= $booking_from && $today <= $booking_to) {
                // booking date check
                $bookingcheck = true;
            }

            if ($checkin >= $start_from && $checkin <= $start_to) {
                // start date check
                $startcheck = true;
            }

            if ($offer->unit_type_id == $myaccommodation->priceBook->unit_id) {
                // unit check
                $unitcheck = true;
            }

            if ($totallength >= floatval($offer->duration_min) && $totallength <= floatval($offer->duration_max)) {
                // duration check
                $durationcheck = true;
            }

            if($offer->is_pduration==1) {
                if($ptotallength >= $offer->pduration_min && $ptotallength<=$offer->pduration_max) {
                    $programcheck = true;
                }
            }
            else {
                $programcheck = true;
            }

            $result = array_filter($offer->campuses->toArray(), function ($item) use ($accommodation) {
                if ($item['id'] == $accommodation['campus']['id']) {
                    return $item;
                }
            });
            if (sizeof($result) > 0) {
                $campuscheck = true;
            }

            if($offer->agency_restriction=='apply_all') { 
                $agencycheck = true;
            }
            elseif($offer->agency_restriction=='apply_certain') {
                if($myuser && $myuser->isOfType(AGENT)){
                    if(in_array($myuser->pardot_account_id, $offer->accounts->pluck('id')->toArray())) {
                        $agencycheck = true;
                    }
                }
            }
            elseif($offer->agency_restriction=='not_apply_certain') {
                $agencycheck = true;
                if($myuser && $myuser->isOfType(AGENT)){
                    if(in_array($myuser->pardot_account_id, $offer->accounts->pluck('id')->toArray())) {
                        $agencycheck = false;
                    }
                }
            }


            if ($bookingcheck == true && $unitcheck == true && $durationcheck == true && $campuscheck == true && $startcheck == true && $programcheck==true && $agencycheck==true) {
                $accommodation_found = array_filter($offer->accommodations->toArray(), function ($item) use ($accommodation) {
                    if ($item['id'] == $accommodation['id']) {
                        return $item;
                    }
                });
                if (sizeof($accommodation_found) > 0) {
                    $found_a = reset($accommodation_found);
                    array_push($acc_offer_arr, $offer);
                    array_push($acc_applied_offers, $offer);
                }

            }
        }

        $result = new \stdClass();
        $result->accommodation = $found_a;
        if(sizeof($acc_offer_arr)==0) {
            $result->offer = null;
        }
        else {
            $result->offer = $acc_offer_arr[0];
        }

        return $result;
    }


    public function perAppAccServiceDiscountCalculation($services,$applied_offers) {

        $final_arr = array();
        foreach($services as $service) {
            $service_comparator_arr = array();
            $myservice = AccommodationService::findorfail($service['id']);
            if($myservice->feetype->name=='Per Application'){
                foreach($applied_offers as $offer) {
                    $service_found = array_filter($offer->services->toArray(), function ($item) use ($service) {
                        if ($item['id'] == $service['id']) {
                            return $item;
                        }
                    });

                    if (sizeof($service_found) > 0) {
                        $found_s = reset($service_found);
                        $discount_price = $this->getOffPriceDiscount($found_s);
                    }
                    else {
                        $discount_price = $myservice->fee;
                    }
                    array_push($service_comparator_arr, $discount_price);
                }

                if(!empty($service_comparator_arr)) {
                    $minimum_price = min($service_comparator_arr);
                    $service['discount_price'] = $minimum_price;
                }
            }
            array_push($final_arr,$service);
        }

        return $final_arr;
    }

//	public function accommodationDiscountCalculation($accommodation, $pricebookid, $unit_arr) {
//		$newpricebook = AccommodationPricebook::findorfail($pricebookid);
//		$myaccommodation = Accommodation::findorfail($accommodation['id']);
//		$unitval = $unit_arr['unitval'];
//		$unitno = $unit_arr['unitno'];
//		$unitrem = $unit_arr['unitrem'];
//		$conditionallength = ceil($unit_arr['days'] / $unitval);
//		if ($newpricebook->is_expired == true) {
//			// check for offer program pricebook expiry
//			$newpricebook = $myaccommodation->priceBook;
//		}
//		$newprice = $this->accommodationPricebookCalculation($newpricebook, $conditionallength, $unitno, $unitrem, $unitval);
//		return $newprice;
//	}

    public function accommodationDiscountCalculation($accommodation, $unit_arr, $offerobj) {
        $myaccommodation = Accommodation::findorfail($accommodation['id']);
        $unitval = $unit_arr['unitval'];
        $unitno = $unit_arr['unitno'];
        $unitrem = $unit_arr['unitrem'];
        $conditionallength = ceil($unit_arr['days'] / $unitval);

	    if($offerobj['pivot']['offer_type']=='Value_off'){
	        $mycheckout = Carbon::parse($accommodation['checkout']);
	        $special_date = Carbon::parse($myaccommodation->special_billing_date);
	        if($myaccommodation->special_billing==true && $mycheckout>$special_date) {
	            $mypricebook = $myaccommodation->specialPriceBook;
            }
            else {
	            $mypricebook = $myaccommodation->priceBook;
            }
            $newprice = $this->accommodationPricebookCalculation($mypricebook, $conditionallength, $unitno, $unitrem, $unitval);
	        $newprice = $newprice - floatval($offerobj['pivot']['item_price']);
        }
        elseif ($offerobj['pivot']['offer_type']=='Percentage_off') {
            $mycheckout = Carbon::parse($accommodation['checkout']);
            $special_date = Carbon::parse($myaccommodation->special_billing_date);
            if($myaccommodation->special_billing==true && $mycheckout>$special_date) {
                $mypricebook = $myaccommodation->specialPriceBook;
            }
            else {
                $mypricebook = $myaccommodation->priceBook;
            }
            $newprice = $this->accommodationPricebookCalculation($mypricebook, $conditionallength, $unitno, $unitrem, $unitval);
            $after_off = 100 - intval($offerobj['pivot']['item_price']);
            $newprice = floatval(($after_off / 100)) * $newprice;
        }
        else {
            $newpricebook = AccommodationPricebook::findorfail($offerobj['pivot']['acc_price_book_id']);
            if ($newpricebook->is_expired == true) {
                // check for offer program pricebook expiry
                $newpricebook = $myaccommodation->priceBook;
            }
            $newprice = $this->accommodationPricebookCalculation($newpricebook, $conditionallength, $unitno, $unitrem, $unitval);
        }
        return $newprice;
    }

	public function accommodationAddonsDiscountCalculation($accommodation, $offer, $addons, $unitarr) {
		$final_arr = array();
		foreach ($addons as $addon) {
			if ($addon['parent_id'] == $accommodation['parent_id']) {
				$addon['discount_price'] = null;

				$addon_found = array_filter($offer->addons->toArray(), function ($item) use ($addon) {
					if ($item['id'] == $addon['id']) {
						return $item;
					}
				});

				if (sizeof($addon_found) > 0) {
					$found_addon = reset($addon_found);
					$overrideprice = $this->getOffPriceDiscount($found_addon, null, $addon, $accommodation);
					$unitval = $unitarr['unitval'];
					$unitno = $unitarr['unitno'];
					$unitrem = $unitarr['unitrem'];

					$unitvalcalculate = $overrideprice / $unitval;
					if ($found_addon['round'] == 'Round_Up_Unit_Type') {
						$unitvalcal = ceil($unitvalcalculate);
					} else if ($found_addon['round'] == 'Round_Down_Unit_Type') {
						$unitvalcal = floor($unitvalcalculate);
					} else {
						$unitvalcal = $unitvalcalculate;
					}
					$unitprice = floatval($unitno) * $overrideprice;
					$dayprice = floatval($unitrem) * $unitvalcal;
					$price = $unitprice + $dayprice;
					$addon['discount_price'] = $price;

				}
			}
			array_push($final_arr, $addon);
		}
		return $final_arr;
	}

	public function accommodationPreferencesDiscountCalculation($accommodation, $offer, $preferences, $unitarr) {
		$final_arr = array();
		foreach ($preferences as $preference) {
			if ($preference['parent_id'] == $accommodation['parent_id']) {
				$preference['discount_price'] = null;

				$preference_found = array_filter($offer->preferences->toArray(), function ($item) use ($preference) {
					if ($item['id'] == $preference['id']) {
						return $item;
					}
				});

				if (sizeof($preference_found) > 0) {
					$found_p = reset($preference_found);
					$overrideprice = $this->getOffPriceDiscount($found_p, null, $preference, $accommodation);
					$unitval = $unitarr['unitval'];
					$unitno = $unitarr['unitno'];
					$unitrem = $unitarr['unitrem'];

					$unitvalcalculate = $overrideprice / $unitval;
					if ($found_p['round'] == 'Round_Up_Unit_Type') {
						$unitvalcal = ceil($unitvalcalculate);
					} else if ($found_p['round'] == 'Round_Down_Unit_Type') {
						$unitvalcal = floor($unitvalcalculate);
					} else {
						$unitvalcal = $unitvalcalculate;
					}
					$unitprice = floatval($unitno) * $overrideprice;
					$dayprice = floatval($unitrem) * $unitvalcal;
					$price = $unitprice + $dayprice;
					$preference['discount_price'] = $price;

				}
			}
			array_push($final_arr, $preference);
		}
		return $final_arr;
	}

	public function accommodationServiceDiscountCalculation($accommodation, $offer, $services, $unitarr) {
		$final_arr = array();
		foreach ($services as $service) {
			if ($service['parent_id'] == $accommodation['parent_id']) {
				$service['discount_price'] = null;

				$service_found = array_filter($offer->services->toArray(), function ($item) use ($service) {
					if ($item['id'] == $service['id']) {
						return $item;
					}
				});

				if (sizeof($service_found) > 0) {
					$found_s = reset($service_found);
					$overrideprice = $this->getOffPriceDiscount($found_s);
					$unitval = $unitarr['unitval'];
					$unitno = $unitarr['unitno'];
					$unitrem = $unitarr['unitrem'];
					if ($found_s['feetype']['name'] == 'Per Unit') {
						$unitvalcalculate = $overrideprice / $unitval;
						$weekprice = intval($unitno) * $overrideprice;
						$dayprice = intval($unitrem) * $unitvalcalculate;
						$price = $weekprice + $dayprice;
					} else {
						$price = $overrideprice;
					}

					$service['discount_price'] = $price;
				}
			}
			array_push($final_arr, $service);
		}
		return $final_arr;
	}

	public function accommodationTransportationDiscountCalculation($accommodation, $offer, $transports) {
		$final_arr = array();
		foreach ($transports as $transport) {
			if ($transport['parent_id'] == $accommodation['parent_id']) {
				$transport['discount_price'] = null;

				$transport_found = array_filter($offer->transportations->toArray(), function ($item) use ($transport) {
					if ($item['id'] == $transport['id']) {
						return $item;
					}
				});

				if (sizeof($transport_found) > 0) {
					$found_t = reset($transport_found);
					if ($found_t['pivot']['type'] == 'value_override') {
						$overrrideprice = $found_t['pivot']['value'];
					} else if ($found_t['pivot']['type'] == 'amount_off') {
						$overrrideprice = floatval($transport['price']) - floatval($found_t['pivot']['value']);
					} else {
						$after_off = 100 - floatval($found_t['pivot']['value']);
						$overrrideprice = floatval($after_off / 100) * floatval($transport['price']);
					}
					$transport['discount_price'] = $overrrideprice;
				}
			}
			array_push($final_arr, $transport);
		}
		return $final_arr;
	}

	/*******  Accommodation Special Offers Section End ***********/

    public function getAppliedOfferPricebooks($criteria, $programs, $accommodations){

        // program part

        $offered_pricebooks = array();
        $programoffers = $this->getProgramSpecialOffers($criteria);
        $accommodationoffers = $this->getAccommodationSpecialOffers($criteria);
        $tiered_arr = $this->tieredArrayConversion($programs);
        $expire_date = null;
        $applied_offers = array();
        $offer_expiry = null;
        foreach ($tiered_arr as $tier) {
            $totallength = 0;
            $earliest_date = Carbon::parse($tier[0]['start_date']);
            foreach ($tier as $prog) {
                $totallength = $totallength + intval($prog['length']);
                $testdate = Carbon::parse($prog['start_date']);
                if ($testdate < $earliest_date) {
                    $earliest_date = $testdate;
                }
            }

            foreach ($tier as $prog) {
                $result = $this->getAppliedOffer($prog, $programoffers, $totallength, $earliest_date, $applied_offers);
                if($result->offer!=null){
                    $filteredobj = array_filter($result->offer->programs->toArray(),function($item) use ($result){
                        if($item['id']==$result->program['id']){
                            return true;
                        }
                    });
                    if(sizeof($filteredobj)>0){
                        if($offer_expiry==null){
                            $offer_expiry = $result->offer->booking_to;
                        }
                        else {
                            $date1 = Carbon::parse($offer_expiry);
                            $date2 = Carbon::parse($result->offer->booking_to);
                            if ($date1 > $date2) {
                                $offer_expiry = $result->offer->booking_to;
                            }
                        }
                        $newpricebookid = reset($filteredobj)['pivot']['price_book_id'];
                        $newpricebook = PriceBook::find($newpricebookid);
                        array_push($offered_pricebooks, $newpricebook);
                    }
                }
            }
        }

        // accommodation part

        $ptotallength = 0;
        $acc_applied_offers = array();
        foreach($programs as $program) {
            $ptotallength = $ptotallength + intval($program['length']);
        }

        foreach ($accommodations as $accommodation) {
            $result = $this->getAppliedAccommodationOffer($accommodationoffers, $accommodation, $ptotallength, $acc_applied_offers);
            if($result->offer!=null){
                $filteredobj = array_filter($result->offer->accommodations->toArray(),function($item) use ($result){
                    if($item['id']==$result->accommodation['id']){
                        return true;
                    }
                });
                
                if(sizeof($filteredobj)>0){
                    if($offer_expiry==null){
                        $offer_expiry = $result->offer->booking_to;
                    }
                    else {
                        $date1 = Carbon::parse($offer_expiry);
                        $date2 = Carbon::parse($result->offer->booking_to);
                        if ($date1 > $date2) {
                            $offer_expiry = $result->offer->booking_to;
                        }
                    }
                    $newpricebookid = reset($filteredobj)['pivot']['acc_price_book_id'];
                    $newpricebook = AccommodationPricebook::find($newpricebookid);
                    if(isset($newpricebook)){
                        array_push($offered_pricebooks, $newpricebook);
                    }
                }
            }
        }

        if($offer_expiry!=null){
            $offer_expiry = Carbon::parse($offer_expiry)->format('d-m-Y');
        }

        return array('pricebooks'=>$offered_pricebooks, 'offer_date'=>$offer_expiry);
    }


	/*******  Tution Protection Service Section ***********/


	public function arrangeProgramsById($programs) {
		$arranged_programs = array();
		foreach($programs as $program) {
			if (array_key_exists($program['id'], $arranged_programs)) {
				//array_push($arranged_programs[$program['id']], $arranged_programs[$program['id']] + intval($program['length']));
                $arranged_programs[$program['id']] = $arranged_programs[$program['id']] + intval($program['length']);
			} else {
				$arranged_programs[$program['id']] = intval($program['length']);
			}
		}
		return $arranged_programs;
	}

	public function tpsCalculation($tpsarr, $criteria, $quotation) {
		$tutionservice = TuitionProtectionService::first();
		$arranged_programs = $this->arrangeProgramsById($tpsarr['programs']);
		if($tutionservice) {
			$this->isTPSApply($arranged_programs, $tutionservice, $criteria['visa'], $tpsarr, $quotation);
		}
	}

	public function isTPSApply($programs, $tpsobj, $visaobj, $tpsarr, $quotation) {

	    $visacheck = false;
		$lengthcheck = false;
		$enablecheck = false;
		$myvisa = Visa::find($visaobj['id']);
		$min_row_length = $tpsobj->details()->first()->course_length;

		if($myvisa->is_student_visa==true){
            $visacheck = true;
        }

        if($tpsobj->is_enabled==true){
            $enablecheck = true;
        }

        foreach($programs as $key => $program){
            if($program >= $min_row_length){
                $lengthcheck = true;
            }
        }


        if($lengthcheck==true && $visacheck==true && $enablecheck==true) {
            // tps installment function call here
            $this->tpsInstallmentPayment($programs, $quotation, $tpsobj, $tpsarr);
        }
        else {
            // only date function here with full fee as first installment
            $this->tpsFullPayment($tpsarr, $quotation);
        }

	}


	public function tpsFullPayment($tpsarr, $quotation){

	    $price = $this->getTotalWithoutProgram($tpsarr, $quotation);
        if (!empty($tpsarr['programs'])) {
            foreach($tpsarr['programs'] as $program){
                if(!is_null($program['discount_price'])){
                    $price = $price + floatval($program['discount_price']);
                }
                else {
                    $price = $price + floatval($program['price']);
                }
            }
        }

        if (!empty($tpsarr['services'])) {
            foreach($tpsarr['services'] as $service){
                if(!is_null($service['discount_price'])){
                    $price = $price + floatval($service['discount_price']);
                }
                else {
                    $price = $price + floatval($service['price']);
                }

            }
        }



        $length = getConfig('APP_FORM_COURSE_LENGTH');
        $duedate = $this->getDueDate($tpsarr['programs'], $length);

        $quotation->installments()->delete();
        $quotation->serviceInstallments()->delete();
        $quotation->default_payment = $price;
        $quotation->default_duedate = $duedate;
        $quotation->update();

    }


    public function tpsInstallmentPayment($programs, $quotation, $tpsobj, $tpsarr){

	    $final_arr = array();
	    $self = $this;
        $price = $this->getTotalWithoutProgram($tpsarr, $quotation);
        $length = getConfig('APP_FORM_COURSE_LENGTH');
        $duedate = $this->getDueDate($tpsarr['programs'], $length);
        //$course_start_date = Carbon::parse($tpsarr['programs'][0]['start_date']);
        if (!empty($tpsarr['services'])) {
            foreach($tpsarr['services'] as $service){
                $serviceobj = FeeService::find($service['id']);
                if($serviceobj->feetype->name!='Per Unit'){
                    if(!is_null($service['discount_price'])){
                        $price = $price + floatval($service['discount_price']);
                    }
                    else {
                        $price = $price + floatval($service['price']);
                    }
                }
            }
        }

        foreach ($programs as $key => $program) {

            $filtered_collection = $tpsobj->details->filter(function ($item) use ($program) {
                return $item->course_length==$program;
            })->values();

            $discount_pricebook = array_filter($tpsarr['programs'], function ($item) use ($key) {
                return ($item['id'] == $key);
            });
           $discount_pricebookid = reset($discount_pricebook)['discount_pricebook'];

            if($filtered_collection->isEmpty()){
                $myobj =  $self->getInstallmentObjectofProgram($key, $filtered_collection, $duedate, $program, $discount_pricebookid, $tpsarr['programs'], $tpsarr['services'],  true);
            }
            else {
                $myobj = $self->getInstallmentObjectofProgram($key, $filtered_collection->first(), $duedate, $program, $discount_pricebookid,$tpsarr['programs'], $tpsarr['services'], false);
            }
            array_push($final_arr, $myobj);

        }

        //dd($final_arr);

        $quotation->installments()->delete();
        $quotation->serviceInstallments()->delete();
        foreach($final_arr as $item) {
            foreach($item->services as $key => $service){
                $serviceinstallment = new QuotationServiceInstallment(['payment1' => $service->instalment1, 'payment2' => $service->instalment2, 'payment3' => $service->instalment3, 'program_id' => $service->program_id, 'service_id' => $key, ]);
                $quotation->serviceInstallments()->save($serviceinstallment);
            }
            if($item->payment2){
                 $installment = new QuotationInstallment(['amount_2' => $item->payment2, 'date_2'=>$item->date2, 'amount_3' => $item->payment3, 'date_3'=>$item->date3, 'program_id'=>$item->program_id]);
                $quotation->installments()->save($installment);
            }
            $price = $price + $item->payment1;
        }

        $quotation->default_payment = $price;
        $quotation->default_duedate = $duedate;
        $quotation->update();
    }

    public function getInstallmentObjectofProgram($programid, $row, $duedate, $length, $discount_pricebookid, $programs_arr, $services, $is_empty) {

        $program = Program::find($programid);
        if(is_null($discount_pricebookid)){
            $mypricebook = $program->priceBook;
        }
        else {
            $mypricebook = PriceBook::find($discount_pricebookid);
        }

        $services_found = array_filter($services, function ($item) use ($programid) {
            return ($item['program_id'] == $programid);
        });

        $perunit_services = array();
        foreach ($services_found as $myservice) {
            $service = FeeService::find($myservice['id']);
            if($service->feetype->name=='Per Unit') {
                if(!array_key_exists($myservice['id'],$perunit_services)){
                    $perunit_services[$myservice['id']] = $myservice['id'];
                }
            }
        }

        $obj = new \stdClass();
        $price1 = null;
        $price2 = null;
        $price3 = null;
        $duedate1 = null;
        $duedate2 = null;
        $duedate3 = null;
        $duedate2touse = null;


        if($is_empty) {
            $price = $this->priceBookCalculation($mypricebook, $length);
            $perunitprice = 0;
            foreach ($perunit_services as $serviceid) {
                $myservice = FeeService::find($serviceid);
                $fee = $myservice->fee * $length;
                $perunitprice = $perunitprice +$fee;
            }
            $price = $price + $perunitprice;
            $obj->payment1 = $price;
            $obj->date1 = $duedate;
            $obj->payment2 = null;
            $obj->date2 = null;
            $obj->payment3 = null;
            $obj->date3 = null;
            $obj->program_id = null;
            $obj->services = array();
        }
        else {
            $price1 = $this->priceBookCalculation($mypricebook, $row->instalment_1_amount, $length);
            $duedate1 = $duedate;
            $services_arr = array();

            foreach ($perunit_services as $serviceid) {
                $myservice = FeeService::find($serviceid);
                $fee = $myservice->fee * $row->instalment_1_amount;
                $obj2 = new \stdClass();
                $obj2->instalment1 = $fee;
                $obj2->instalment2 = null;
                $obj2->instalment3 = null;
                $obj2->program_id = $programid;
                $services_arr[$serviceid] = $obj2;
            }


            if($row->instalment_2_amount!=0) {

                $price2 = $this->priceBookCalculation($mypricebook, $row->instalment_2_amount, $length);

                foreach ($perunit_services as $serviceid) {
                    $myservice = FeeService::find($serviceid);
                    $fee = $myservice->fee * $row->instalment_2_amount;
                    $services_arr[$serviceid]->instalment2 = $fee;
                }

                $duedate2 = $this->getSecondThirdDueDate($programs_arr, $row->instalment_1_amount, $program);
                $duedate2touse = $duedate2->format('Y-m-d');
                $duedate2->subWeeks($row->instalment_2_due_date);
                $duedate2 = $this->getLastWeekFriday($duedate2);
            }

            if($row->instalment_3_amount!=0) {
                $price3 = $this->priceBookCalculation($mypricebook, $row->instalment_3_amount, $length);

                foreach ($perunit_services as $serviceid) {
                    $myservice = FeeService::find($serviceid);
                    $fee = $myservice->fee * $row->instalment_3_amount;
                    $services_arr[$serviceid]->instalment3 = $fee;
                }

                $duedate3 = $this->getSecondThirdDueDate($programs_arr, $row->instalment_2_amount, $program, $duedate2touse);
                $duedate3->subWeeks($row->instalment_3_due_date);
                $duedate3 = $this->getLastWeekFriday($duedate3);
            }

            $obj->payment1 = $price1;
            $obj->date1 = $duedate1;
            $obj->payment2 = $price2;
            $obj->date2 = $duedate2->format('Y-m-d');
            $obj->payment3 = $price3;
            $obj->date3 = $duedate3;
            $obj->program_id = $programid;
            $obj->services = $services_arr;
        }

        return $obj;
    }

    public function getSecondThirdDueDate($programs, $length_to_add, $programobj, $second_installment_date=NULL) {
        $filtered_program = array_filter($programs, function ($item) use ($programobj) {
            return ($item['id'] == $programobj->id);
        });
        $filtered_program = reset($filtered_program);

        if(is_null($second_installment_date)){
            $start_date = Carbon::parse($filtered_program['start_date']);
        }
        else {
            $start_date = Carbon::parse($second_installment_date);
        }

        $breakslength = $this->checkForBreaksDueDate($start_date, $length_to_add, $programobj);
        $holidaylength = $this->checkForHolidaysDueDate($start_date, $length_to_add, $breakslength);
        if($length_to_add >= intval($filtered_program['length'])) {
            $otherprogramlength = $this->checkForProgramsInPath($start_date, $length_to_add, $breakslength, $holidaylength, $programs, $programobj->id);
        }
        else {
            $otherprogramlength = 0;
        }

        $mylength = $length_to_add + $breakslength + $holidaylength + $otherprogramlength;
        $start_date->addWeeks($mylength);
        return $start_date;
    }

    public function checkForBreaksDueDate($start_date, $length, $program) {

        $stopper = $length;
        $details = $program->calendar->details;
        $counter = 0;
        for($i =0 ; $i< sizeof($details) ; $i++){
            if($stopper < 0){
                break;
            }

            $newstartdate = Carbon::parse($details[$i]->start_date);
            if(is_null($details[$i]->getOriginal('end_date'))) {
                $diff_in_weeks = 1;
            }
            else {
                $diff_in_weeks = $details[$i]->weeks;
            }

            if($newstartdate >= $start_date){
                if($details[$i]->off_days == 1){
                    $counter = $counter + $diff_in_weeks;
                    $stopper = $stopper + $diff_in_weeks;
                }
                $stopper = $stopper - $diff_in_weeks;
            }
        }

        return $counter;
    }


    public function checkForHolidaysDueDate($start_date, $length, $lengthincrement) {

        $calendarholyday_id = DB::table('settings')->where('key', 'holiday_calendar')->pluck('value');
        $holidaycalendar = Calender::where('id', $calendarholyday_id)->with('details')->first();
        $length = $length + $lengthincrement;
        $end_date = Carbon::parse($start_date->format('Y-m-d'))->addWeeks($length);
        $weeks = 0;

        foreach ($holidaycalendar->details as $entry) {
            if($entry->off_days==1){
                $check1 = true;
                $check2 = true;
                $check3 = true;
                $s2 = Carbon::parse($entry->start_date);
                $e2 = Carbon::parse($entry->end_date);

                if($s2>=$start_date && $e2<=$end_date){
                    $check1 = false;
                }

                if($e2>=$start_date && $e2<=$end_date){
                    $check2 = false;
                }

                if($s2<=$end_date && $e2>=$end_date){
                    $check3 = false;
                }

                if($check1==false || $check2==false || $check3==false) {
                    $weeks = $weeks + intval($entry->weeks);
                }
            }
        }

        return $weeks;

    }


    public function checkForProgramsInPath($start_date, $length, $lengthincrement1, $lengthincrement2, $programs, $programid) {
        $count = 0;
        $checker = 0;
        $filtered_programs = array_filter($programs, function ($item) use ($programid) {
            return ($item['id'] == $programid);
        });

        for($i=0;$i<sizeof($filtered_programs);$i++) {
                if(isset($filtered_programs[$i+1])) {
                    $e1 = Carbon::parse($filtered_programs[$i]['end_date'])->addDays(3);
                    $s2 = Carbon::parse($filtered_programs[$i+1]['start_date']);
                    $s1 = Carbon::parse($filtered_programs[$i]['start_date']);
                    $pdiff = $e1->diffInWeeks($s1);
                    $checker = $checker+$pdiff;
                    if($checker <= $length) {
                        $diff = $s2->diffInWeeks($e1);
                        $count = $count+$diff;
                    }
                }

        }
        return $count;
    }

    public function getDueDate($programs, $length){

        $start_date = Carbon::parse($programs[0]['start_date']);
        $unitno = $length;
        $tickbox = true;
        $unitid = getConfig('APP_FORM_STUDY_UNIT_ID');
        $myunit = StudyUnit::find($unitid);
        if($myunit->value){
            $days = intval($unitno)*intval($myunit->value);
            $start_date->subDays($days);
        }

        if($start_date < Carbon::now()) {
            $start_date = Carbon::now();
            $tickbox = false;
        }

        if($tickbox){
            $start_date_driday = $this->getLastWeekFriday($start_date);
        }
        else {
            $start_date_driday = $start_date;
        }

        return $start_date_driday->format('Y-m-d');
    }

    public function getLastWeekFriday($mydate) {
        return $mydate->previous(Carbon::FRIDAY);
    }


    public function getTotalWithoutProgram($tpsarr, $quotation){
	    $price = 0;

        if (!empty($tpsarr['exams'])) {
            foreach($tpsarr['exams'] as $exam){
                if(!is_null($exam['discount_price'])){
                    $price = $price + floatval($exam['discount_price']);
                }
                else {
                    $price = $price + floatval($exam['price']);
                }
            }
        }


        if (!empty($tpsarr['addons'])) {
            foreach($tpsarr['addons'] as $addon){
                $price = $price + floatval($addon['price']);
            }
        }


        if (!empty($tpsarr['experiences'])) {
            foreach($tpsarr['experiences'] as $experience){
                $price = $price + floatval($experience['price']);
            }
        }


        if (!empty($tpsarr['healths'])) {
            foreach($tpsarr['healths'] as $health){
                $price = $price + floatval($health['price']);
            }
        }

        if (!empty($tpsarr['internships'])) {
            foreach($tpsarr['internships'] as $internship){
                $price = $price + floatval($internship['price']);
                foreach ($internship['services'] as $iservice) {
                    $price = $price + floatval($iservice['price']);
                }
            }
        }

        if (!empty($tpsarr['accommodations'])) {
            foreach($tpsarr['accommodations'] as $acc){
                if(!is_null($acc['discount_price'])){
                    $price = $price + floatval($acc['discount_price']);
                }
                else {
                    $price = $price + floatval($acc['price']);
                }
            }
        }

        if (!empty($tpsarr['accservices'])) {
            foreach($tpsarr['accservices'] as $service){
                if(!is_null($service['discount_price'])){
                    $price = $price + floatval($service['discount_price']);
                }
                else {
                    $price = $price + floatval($service['price']);
                }
            }
        }

        if (!empty($tpsarr['accpreferences'])) {
            foreach($tpsarr['accpreferences'] as $preference){
                if(!is_null($preference['discount_price'])){
                    $price = $price + floatval($preference['discount_price']);
                }
                else {
                    $price = $price + floatval($preference['price']);
                }

            }
        }

        if (!empty($tpsarr['accaddons'])) {
            foreach($tpsarr['accaddons'] as $addon){
                if(!is_null($addon['discount_price'])){
                    $price = $price + floatval($addon['discount_price']);
                }
                else {
                    $price = $price + floatval($addon['price']);
                }

            }
        }

        if (!empty($tpsarr['transportation'])) {
            foreach($tpsarr['transportation'] as $trans){
                if(!is_null($trans['discount_price'])){
                    $price = $price + floatval($trans['discount_price']);
                }
                else {
                    $price = $price + floatval($trans['price']);
                }

            }
        }

        if (!empty($tpsarr['transportaddons'])) {
            foreach($tpsarr['transportaddons'] as $addon){
                $price = $price + floatval($addon['price']);
            }
        }

        $pricefull = $price;

        if (!empty($tpsarr['programs'])) {
            foreach($tpsarr['programs'] as $program){
                if(!is_null($program['discount_price'])){
                    $pricefull = $pricefull + floatval($program['discount_price']);
                }
                else {
                    $pricefull = $pricefull + floatval($program['price']);
                }

            }
        }

        if (!empty($tpsarr['services'])) {
            foreach($tpsarr['services'] as $service){
                    if(!is_null($service['discount_price'])){
                        $pricefull = $pricefull + floatval($service['discount_price']);
                    }
                    else {
                        $pricefull = $pricefull + floatval($service['price']);
                    }
            }
        }

        $paymentmethod = $quotation->paymentMethod;
        if($paymentmethod->surcharge=='$') {
            $price = $price + floatval($paymentmethod->fee);
        }
        else if($paymentmethod->surcharge=='%') {
            $percentage = ($paymentmethod->fee * floatval($pricefull))/100;
            $price = $price + $percentage;
        }

       return $price;

    }


    public function calculateTax($quoteobj){

        $myaddons = $quoteobj->addons;
        $myexperiences = $quoteobj->experiences;
        $qtransports = $quoteobj->transportationTypes;
        $qtransportaddons = $quoteobj->transportationAddons;
        $qaccservices = $quoteobj->accommodationServices;
        $myservices = $quoteobj->services;
        $tax = 0;

        foreach ($myaddons as $myaddon) {
            if(!is_null($myaddon->tax_id)) {
                $taxpercent = 1+(floatval($myaddon->quoteTax->percentage/100));
                $beforeprice = floatval($myaddon->fee)/$taxpercent;
                $mytax = floatval($myaddon->fee - $beforeprice);
                $tax = $tax + $mytax;
            }
        }

        foreach ($myexperiences as $myexperience) {
            if(!is_null($myexperience->tax_id)) {
                $taxpercent = 1+(floatval($myexperience->quoteTax->percentage/100));
                $beforeprice = floatval($myexperience->fee)/$taxpercent;
                $mytax = floatval($myexperience->fee - $beforeprice);
                $tax = $tax + $mytax;
            }
        }


        foreach ($qtransports as $qtransport) {
            if(!is_null($qtransport->tax_id)) {
                $taxpercent = 1+(floatval($qtransport->quoteTax->percentage/100));
                if($qtransport->pivot->discount_price==null){
                    $beforeprice = floatval($qtransport->pivot->price)/$taxpercent;
                    $mytax = floatval($qtransport->pivot->price - $beforeprice);
                }
                else {
                    $beforeprice = floatval($qtransport->pivot->discount_price)/$taxpercent;
                    $mytax = floatval($qtransport->pivot->discount_price - $beforeprice);
                }

                $tax = $tax + $mytax;
            }
        }

        foreach ($qtransportaddons as $qtransportaddon) {
            if(!is_null($qtransportaddon->tax_id)) {
                $taxpercent = 1+(floatval($qtransportaddon->quoteTax->percentage/100));
                $beforeprice = floatval($qtransportaddon->fee)/$taxpercent;
                $mytax = floatval($qtransportaddon->fee - $beforeprice);
                $tax = $tax + $mytax;
            }
        }

        foreach($myservices as $myservice){
            if(!is_null($myservice->tax_id)) {
                $taxpercent = 1+(floatval($myservice->quoteTax->percentage/100));
                if($myservice->pivot->discount_price==null){
                    $beforeprice = floatval($myservice->pivot->price)/$taxpercent;
                    $mytax = floatval($myservice->pivot->price - $beforeprice);
                }
                else {
                    $beforeprice = floatval($myservice->pivot->discount_price)/$taxpercent;
                    $mytax = floatval($myservice->pivot->discount_price - $beforeprice);
                }

                $tax = $tax + $mytax;
            }
        }

        foreach($qaccservices as $accservice){
            if(!is_null($accservice->tax_id)) {
                $taxpercent = 1+(floatval($accservice->quoteTax->percentage/100));
                if($accservice->pivot->discount_price==null){
                    $beforeprice = floatval($accservice->pivot->price)/$taxpercent;
                    $mytax = floatval($accservice->pivot->price - $beforeprice);
                }
                else {
                    $beforeprice = floatval($accservice->pivot->discount_price)/$taxpercent;
                    $mytax = floatval($accservice->pivot->discount_price - $beforeprice);
                }

                $tax = $tax + $mytax;
            }
        }

        $paymentmethod_price = 0;
        $paymentmethod = $quoteobj->paymentMethod;
        if($paymentmethod->surcharge=='$') {
            $paymentmethod_price = floatval($paymentmethod->fee);
        }
        else if($paymentmethod->surcharge=='%') {
            $total = $this->getTotalPriceWithoutPaymentMethod($quoteobj);
            $paymentmethod_price = ($paymentmethod->fee * floatval($total))/100;
        }

        if(!is_null($paymentmethod->tax_id)) {
            $taxpercent = 1+(floatval($paymentmethod->quoteTax->percentage/100));
            $beforeprice = floatval($paymentmethod_price)/$taxpercent;
            $mytax = floatval($paymentmethod_price - $beforeprice);
            $tax = $tax + $mytax;
        }

        $quoteobj->tax_amount = $tax;
        $quoteobj->update();
    }

    public function getTotalPriceWithoutPaymentMethod($quoteobj) {
        $price = 0;

        foreach($quoteobj->exams as $exam){
            if(!is_null($exam->pivot->discount_price)){
                $price = $price + floatval($exam->pivot->discount_price);
            }
            else {
                $price = $price + floatval($exam->fee);
            }
        }


        foreach($quoteobj->addons as $addon){
            $price = $price + floatval($addon->fee);
        }




        foreach($quoteobj->experiences as $experience){
            $price = $price + floatval($experience->fee);
        }


        foreach($quoteobj->healths as $health){
            $price = $price + floatval($health->pivot->price);
        }



        foreach($quoteobj->internships as $internship){
            $price = $price + floatval($internship->fee);
            foreach ($internship->feeinternshipservices as $iservice) {
                $price = $price + floatval($iservice->fee);
            }
        }


        foreach($quoteobj->accommodations as $acc){
            if(!is_null($acc->pivot->discount_price)){
                $price = $price + floatval($acc->pivot->discount_price);
            }
            else {
                $price = $price + floatval($acc->pivot->price);
            }
        }



        foreach($quoteobj->accommodationServices as $service){
            if(!is_null($service->pivot->discount_price)){
                $price = $price + floatval($service->pivot->discount_price);
            }
            else {
                $price = $price + floatval($service->pivot->price);
            }
        }



        foreach($quoteobj->accommodationPreferences as $preference){
            if(!is_null($preference->pivot->discount_price)){
                $price = $price + floatval($preference->pivot->discount_price);
            }
            else {
                $price = $price + floatval($preference->pivot->price);
            }

        }



        foreach($quoteobj->accommodationAddons as $addon){
            if(!is_null($addon->pivot->discount_price)){
                $price = $price + floatval($addon->pivot->discount_price);
            }
            else {
                $price = $price + floatval($addon->pivot->price);
            }

        }



        foreach($quoteobj->transportationTypes as $trans){
            if(!is_null($trans->pivot->discount_price)){
                $price = $price + floatval($trans->pivot->discount_price);
            }
            else {
                $price = $price + floatval($trans->pivot->price);
            }

        }


        foreach($quoteobj->transportationAddons as $addon){
            $price = $price + floatval($addon->fee);
        }


        foreach($quoteobj->programs as $program){
            if(!is_null($program->pivot->discount_price)){
                $price = $price + floatval($program->pivot->discount_price);
            }
            else {
                $price = $price + floatval($program->pivot->price);
            }

        }


        foreach($quoteobj->services as $service){
            if(!is_null($service->pivot->discount_price)){
                $price = $price + floatval($service->pivot->discount_price);
            }
            else {
                $price = $price + floatval($service->pivot->price);
            }
        }

        return $price;

    }

	/*******  Tution Protection Service Section End ***********/






    /*******  Special Billing for accommodation ***********/

    public function specialBillingCalculations($accommodations, $services, $addons, $preferences) {

        $tobreak_arr = array();
        foreach ($accommodations as $key =>$accommodation) {
            $accommodationobj = Accommodation::find($accommodation['id']);
            $end_date = Carbon::parse($accommodation['checkout']);
            $start_date = Carbon::parse($accommodation['checkin']);
            if($accommodationobj->special_billing==true){
                $today = Carbon::now();
                $special_date = Carbon::parse($accommodationobj->special_billing_date);
                if($today <= $special_date){
                    if($start_date<$special_date && $end_date > $special_date) {
                        array_push($tobreak_arr, $accommodation);
                    }
                    //else if($accommodation['is_break']==false){
                        if($end_date <= $special_date) {
                            $pb = $accommodationobj->priceBook;
                        }
                        else{
                            $pb = $accommodationobj->specialPriceBook;
                        }

                        $unit_arr = $this->getUnitandRemDays($accommodation['checkin'], $accommodation['checkout'], $pb->unit_id);
                        $unitval = $unit_arr['unitval'];
                        $unitno = $unit_arr['unitno'];
                        $unitrem = $unit_arr['unitrem'];
                        $conditionallength = ceil($unit_arr['days'] / $unitval);
                        $price = $this->accommodationPricebookCalculation($pb, $conditionallength, $unitno, $unitrem, $unitval);
                        $accommodations[$key]['price'] = $price;
                    //}
                }
                else {
                    //if($accommodation['is_break']==false){
                        if($end_date <= $special_date) {
                            $pb = $accommodationobj->priceBook;
                        }
                        else{
                            $pb = $accommodationobj->specialPriceBook;
                        }

                        $unit_arr = $this->getUnitandRemDays($accommodation['checkin'], $accommodation['checkout'], $pb->unit_id);
                        $unitval = $unit_arr['unitval'];
                        $unitno = $unit_arr['unitno'];
                        $unitrem = $unit_arr['unitrem'];
                        $conditionallength = ceil($unit_arr['days'] / $unitval);
                        $price = $this->accommodationPricebookCalculation($pb, $conditionallength, $unitno, $unitrem, $unitval);
                        $accommodations[$key]['price'] = $price;
                    //}

                }
            }
        }

        if(sizeof($tobreak_arr) > 0){
            $this->breakAccommodations($tobreak_arr, $accommodations, $services, $preferences, $addons);
        }

        $returned_obj = array();
        $returned_obj['accommodations'] = array_values ( $accommodations );
        $returned_obj['services'] = array_values ( $services );
        $returned_obj['preferences'] = array_values ( $preferences );
        $returned_obj['addons'] = array_values ( $addons );
        return $returned_obj;
    }


    public function breakAccommodations($baccomodations, &$accommodations, &$services, &$preferences, &$addons) {
        foreach($baccomodations as $key=>$baccomodation){
            $myindex = array_search($baccomodation['parent_id'], array_column($accommodations, 'parent_id'));
            //array_splice($accommodations, $key, 1);
            unset($accommodations[$myindex]);
            $randid = rand(1001,9000);
            $this->breakSingleAccommodationToTwo($baccomodation, $accommodations, $randid);
            $this->breakAccServices($services, $baccomodation['parent_id'], $randid, $baccomodation);
            $this->breakAccPreferences($preferences, $baccomodation['parent_id'], $randid, $baccomodation);
            $this->breakAccAddons($addons, $baccomodation['parent_id'], $randid, $baccomodation);
        }
    }


    public function breakSingleAccommodationToTwo($baccomodation, &$accommodations, $randid) {

        $accommodationobj = Accommodation::find($baccomodation['id']);
        $special_date_obj = Carbon::parse($baccomodation['self']['special_billing_date']);
        $startdate2 = $special_date_obj->format('d-m-Y');
        $enddate1 = $special_date_obj->format('d-m-Y');
        $newpb = $accommodationobj->specialPriceBook;
        $opb = $accommodationobj->priceBook;
        $acc1 = $this->getAccommodationPiece($baccomodation, $baccomodation['checkin'], $enddate1, $opb);
        $acc2 = $this->getAccommodationPiece($baccomodation, $startdate2, $baccomodation['checkout'], $newpb, $randid);
        array_push($accommodations, $acc1);
        array_push($accommodations, $acc2);

    }

    public function getAccommodationPiece($acc, $checkin, $checkout, $pb, $parentid=NULL) {
        $unit_arr = $this->getUnitandRemDays($checkin, $checkout, $pb->unit_id);
        $unitval = $unit_arr['unitval'];
        $unitno = $unit_arr['unitno'];
        $unitrem = $unit_arr['unitrem'];
        $conditionallength = ceil($unit_arr['days'] / $unitval);
        $price = $this->accommodationPricebookCalculation($pb, $conditionallength, $unitno, $unitrem, $unitval);
        $acc['price'] = $price;
        $acc['unitno'] = $unitno;
        $acc['unitrem'] = $unitrem;
        $acc['checkin'] = $checkin;
        $acc['checkout'] = $checkout;
        if(!is_null($parentid)) {
            $acc['parent_id'] = $parentid;
            $acc['rand_id'] = $parentid;
            $acc['is_broken'] = true;
        }
        return $acc;
    }


    public function SpecialPricingCalculationWithoutBreaks($accommodations){
        foreach ($accommodations as $key=>$accommodation) {
            $accobj = Accommodation::find($accommodation['id']);
            $end_date = Carbon::parse($accommodation['checkout']);
            if($accobj->special_billing==true){
                $special_date = Carbon::parse($accobj->special_billing_date);
                if($end_date <= $special_date) {
                    $pb = $accobj->priceBook;
                }
                else{
                    $pb = $accobj->specialPriceBook;
                }

                $unit_arr = $this->getUnitandRemDays($accommodation['checkin'], $accommodation['checkout'], $pb->unit_id);
                $unitval = $unit_arr['unitval'];
                $unitno = $unit_arr['unitno'];
                $unitrem = $unit_arr['unitrem'];
                $conditionallength = ceil($unit_arr['days'] / $unitval);
                $price = $this->accommodationPricebookCalculation($pb, $conditionallength, $unitno, $unitrem, $unitval);
                $accommodations[$key]['price'] = $price;
            }
        }
        return $accommodations;
    }



    /* -----------  Service Part    --------------------- */

    public function breakAccServices(&$services, $oldrand, $randid, $baccomodation) {
        $to_break_services = array_filter($services, function($item) use ($oldrand) {
            $myservice = AccommodationService::find($item['id']);
            if($myservice->feetype->name=='Per Unit'){
                return $item['parent_id']==$oldrand;
            }
        });

        foreach($to_break_services as $key=>$breakservice){
            //$myindex = array_search($breakservice['parent_id'], array_column($services, 'parent_id'));
            //array_splice($services, $key, 1);
            unset($services[$key]);
            $this->breakSingleAccommodationServiceToTwo($breakservice, $services, $randid, $baccomodation);
        }


    }

    public function breakSingleAccommodationServiceToTwo($breakservice, &$services, $randid, $baccomodation){
        $special_date_obj = Carbon::parse($baccomodation['self']['special_billing_date']);
        $startdate2 = $special_date_obj->format('d-m-Y');
        $enddate1 = $special_date_obj->format('d-m-Y');
        $startdate1 = $breakservice['checkin'];
        $enddate2 = $breakservice['checkout'];
        $accommodation = Accommodation::find($baccomodation['id']);
        $service1 = $this->getAccServicePiece($breakservice, $startdate1, $enddate1, $accommodation->priceBook);
        $service2 = $this->getAccServicePiece($breakservice, $startdate2, $enddate2, $accommodation->specialPriceBook, $randid);
        array_push($services, $service1);
        array_push($services, $service2);

    }

    public function getAccServicePiece($breakservice, $checkin, $checkout, $pricebook, $parentid=NULL) {

        $service = AccommodationService::findorfail($breakservice['id']);
        $unit_arr = $this->getUnitandRemDays($checkin, $checkout, $pricebook->unit_id);
        $unitval = $unit_arr['unitval'];
        $unitno = $unit_arr['unitno'];
        $unitrem = $unit_arr['unitrem'];
        $converted_length = 0;
        $unitremval = 1;
        if ($unitval) {
            $converted_length = $unitno;
            $unitremval = $unitrem/$unitval;
        }

        if ($service->feetype->name == 'Per Unit') {
            if ($unitval) {
                $price = floatval($service->fee) * $converted_length;
                $price = $price + ($service->fee * $unitremval);
            } else {
                $price = floatval($service->fee);
            }
        } else {
            $price = floatval($service->fee);
        }
        $price = round($price, 2);
        $breakservice['price'] = $price;
        $breakservice['unitno'] = $unitno;
        $breakservice['unitrem'] = $unitrem;
        $breakservice['checkin'] = $checkin;
        $breakservice['checkout'] = $checkout;
        if(!is_null($parentid)) {
            $breakservice['parent_id'] = $parentid;
        }
        return $breakservice;
    }


    /* -----------  Service Part End   --------------------- */




    /* -----------  Preference Part   --------------------- */


    public function breakAccPreferences(&$preferences, $oldrand, $randid, $baccomodation) {

        $to_break_preferences = array_filter($preferences, function($item) use ($oldrand) {
            $mypreference = AccommodationPreference::find($item['id']);
            if($mypreference->feetype->name=='Per Unit' && $mypreference->special_billing==true){
                return $item['parent_id']==$oldrand;
            }
        });

        foreach($to_break_preferences as $key=>$breakpreference){
            //$myindex = array_search($breakpreference['parent_id'], array_column($preferences, 'parent_id'));
            unset($preferences[$key]);
            //array_splice($preferences, $key, 1);
            $this->breakSingleAccommodationPreferenceToTwo($breakpreference, $preferences, $randid, $baccomodation);
        }
    }


    public function breakSingleAccommodationPreferenceToTwo($breakpreference, &$preferences, $randid, $baccomodation){
        $special_date_obj = Carbon::parse($baccomodation['self']['special_billing_date']);
        $startdate2 = $special_date_obj->format('d-m-Y');
        $enddate1 = $special_date_obj->format('d-m-Y');
        $startdate1 = $breakpreference['checkin'];
        $enddate2 = $breakpreference['checkout'];
        $preference1 = $this->getAccPreferencePiece($breakpreference, $startdate1, $enddate1, $baccomodation);
        $preference2 = $this->getAccPreferencePiece($breakpreference, $startdate2, $enddate2, $baccomodation, $randid);
        array_push($preferences, $preference1);
        array_push($preferences, $preference2);

    }


    public function getAccPreferencePiece($breakpreference, $checkin, $checkout, $baccomodation, $parentid=NULL) {
        $mypreference = AccommodationPreference::find($breakpreference['id']);
        if(!is_null($parentid) && $mypreference->special_billing==true) {
            $price = $this->singleAccommodationPreferenceCalculation($breakpreference['id'], $checkin, $checkout, $baccomodation['id'], true);
        }
        else {
            $price = $this->singleAccommodationPreferenceCalculation($breakpreference['id'], $checkin, $checkout, $baccomodation['id']);
        }

        $accommodation = Accommodation::find($baccomodation['id']);
        $unit_arr = $this->getUnitandRemDays($checkin, $checkout, $accommodation->priceBook->unit_id);
        $unitno = $unit_arr['unitno'];
        $unitrem = $unit_arr['unitrem'];
        $breakpreference['price'] = $price;
        $breakpreference['unitno'] = $unitno;
        $breakpreference['unitrem'] = $unitrem;
        $breakpreference['checkin'] = $checkin;
        $breakpreference['checkout'] = $checkout;
        if(!is_null($parentid)) {
            $breakpreference['parent_id'] = $parentid;
            $breakpreference['is_broken'] = true;
        }
        return $breakpreference;
    }

    /* -----------  Preference Part End   --------------------- */




    /* -----------  Addons Part   --------------------- */


    public function breakAccAddons(&$addons, $oldrand, $randid, $baccomodation) {
        $to_break_addons = array_filter($addons, function($item) use ($oldrand) {
            $myaddon = AccommodationAddon::find($item['id']);
            if($myaddon->addon_charge=='ac_length' && $myaddon->special_billing==true){
                return $item['parent_id']==$oldrand;
            }
            else if($myaddon->addon_charge=='mix_length'){
                if($item['plength'] > $item['unitno'] && $myaddon->special_billing==true) {
                    return $item['parent_id']==$oldrand;
                }

            }
        });


        foreach($to_break_addons as $key=>$breakaddon){
            //$myindex = array_search($breakaddon['parent_id'], array_column($addons, 'parent_id'));
            //array_splice($addons, $key, 1);
            unset($addons[$key]);
            $this->breakSingleAccommodationAddonToTwo($breakaddon, $addons, $randid, $baccomodation);
        }
    }


    public function breakSingleAccommodationAddonToTwo($breakaddon, &$addons, $randid, $baccomodation) {
        $special_date_obj = Carbon::parse($baccomodation['self']['special_billing_date']);
        $startdate2 = $special_date_obj->format('d-m-Y');
        $enddate1 = $special_date_obj->format('d-m-Y');
        $startdate1 = $breakaddon['checkin'];
        $enddate2 = $breakaddon['checkout'];
        $addon1 = $this->getAccAddonPiece($breakaddon, $startdate1, $enddate1, $baccomodation);
        $addon2 = $this->getAccAddonPiece($breakaddon, $startdate2, $enddate2, $baccomodation, $randid);
        array_push($addons, $addon1);
        array_push($addons, $addon2);
    }


    public function getAccAddonPiece($breakaddon, $checkin, $checkout, $baccomodation, $parentid=NULL) {

        $myaddon = AccommodationAddon::find($breakaddon['id']);
        if(!is_null($parentid) && $myaddon->special_billing==true){
            $price = $this->singleAccommodationAddonCalculation($breakaddon['id'], $checkin, $checkout, $baccomodation['id'], $breakaddon['plength'], true);
        }
        else {
            $price = $this->singleAccommodationAddonCalculation($breakaddon['id'], $checkin, $checkout, $baccomodation['id'], $breakaddon['plength']);
        }
        $accommodation = Accommodation::find($baccomodation['id']);
        $unit_arr = $this->getUnitandRemDays($checkin, $checkout, $accommodation->priceBook->unit_id);
        $unitno = $unit_arr['unitno'];
        $unitrem = $unit_arr['unitrem'];
        $breakaddon['price'] = $price;
        $breakaddon['unitno'] = $unitno;
        $breakaddon['unitrem'] = $unitrem;
        $breakaddon['checkin'] = $checkin;
        $breakaddon['checkout'] = $checkout;
        if(!is_null($parentid)) {
            $breakaddon['parent_id'] = $parentid;
            $breakaddon['is_broken'] = true;

        }
        return $breakaddon;
    }


    /* -----------  Addons Part End   --------------------- */

    /*******  Special Billing for accommodation End ***********/



    /*******  Mandatory Items Validation ***********/

    public function checkMandatoryItems($programs, &$services, $accommodations, &$accommodation_services, &$accommodation_addons, &$accommodation_preferences) {

        if(!empty($programs)) {
            $this->validateProgramServices($programs, $services);
        }

        if(!empty($accommodations)) {
            $this->validateAccommodationServices($accommodations, $accommodation_services);
            $this->validateAccommodationAddons($accommodations, $accommodation_addons, $programs);
            $this->validateAccommodationPreferences($accommodations, $accommodation_preferences);
        }


    }

    public function validateProgramServices($programs, &$services){
        foreach ($programs as $program) {
            $myprogram = Program::find($program['id']);
            $pservices = $myprogram->saveServices;
            foreach ($pservices as $pservice) {
                if($pservice->pivot->is_mandatory==true) {
                    $searched = array_search($pservice->id, array_column($services, 'id'));
                    if($searched===false){
                        $this->insertProgramService($pservice,$program, $services);
                    }
                }
            }
        }
    }

    public function insertProgramService($service, $program, &$services){
        $price = $this->singleServiceFeeCalculation($service->id, $program['length']);
        $myarr = array(
            'id' => $service->id,
            'name' => $service->name,
            'price' => $price,
            'discount_price' => null,
            'parent_id' => $program['parent_id'],
            'program_id' => $program['id'],
            'length' => $program['length'],
            'type' => 'service',
            'tax'=> $service->quoteTax,
            'self' => $service
        );
        array_push($services, $myarr);
    }

    public function validateAccommodationServices($accommodations, &$accservices){
        foreach ($accommodations as $accommodation) {
            $myacc = Accommodation::find($accommodation['id']);
            $aservices = $myacc->saveServices;
            foreach ($aservices as $aservice) {
                if($aservice->pivot->is_mandatory==true) {
                    $searched = array_search($aservice->id, array_column($accservices, 'id'));
                    if($searched===false){
                        $this->insertAccommodationService($aservice,$accommodation, $accservices);
                    }
                }
            }
        }
    }

    public function insertAccommodationService($service, $accommodation, &$accservices){
        $price = $this->singleAccommodationServiceCalculation($service->id, $accommodation['checkin'], $accommodation['checkout'],$accommodation['id']);
        $myarr = array(
            'id' => $service->id,
            'name' => $service->name,
            'price' => $price,
            'discount_price' => null,
            'parent_id' => $accommodation['parent_id'],
            'accommodation_id' => $accommodation['id'],
            'type' => 'accommodationservices',
            'checkin' => $accommodation['checkin'],
            'checkout' => $accommodation['checkout'],
            'tax' => $service->quoteTax,
            'self' => $service
        );
        array_push($accservices, $myarr);
    }

    public function validateAccommodationAddons($accommodations, &$accaddons, $programs){
        foreach ($accommodations as $accommodation) {
            $myacc = Accommodation::find($accommodation['id']);
            $aaddons = $myacc->saveAddons;
            foreach ($aaddons as $aaddon) {
                if($aaddon->pivot->is_mandatory==true) {
                    $searched = array_search($aaddon->id, array_column($accaddons, 'id'));
                    if($searched===false){
                        $this->insertAccommodationAddon($aaddon,$accommodation, $accaddons, $programs);
                    }
                }
            }
        }
    }

    public function insertAccommodationAddon($aaddon, $accommodation, &$accaddons, $programs){

        $plength = 0;
        foreach ($programs as $program){
            $plength = $plength + intval($program['length']);
        }

        $price = $this->singleAccommodationAddonCalculation($aaddon->id, $accommodation['checkin'], $accommodation['checkout'], $accommodation['id'], $plength);
        $myarr = array(
            'id' => $aaddon->id,
            'name' => $aaddon->name,
            'price' => $price,
            'discount_price' => null,
            'parent_id' => $accommodation['parent_id'],
            'accommodation_id' => $accommodation['id'],
            'type' => 'accommodationaddons',
            'checkin' => $accommodation['checkin'],
            'checkout' => $accommodation['checkout'],
            'plength' => $plength,
            'charge_type' => $aaddon->addon_charge,
            'unitno' => $accommodation['unitno'],
            'unitrem' => $accommodation['unitrem'],
            'unitname' => $accommodation['unit'],
            'is_broken' => false,
            'self' => $aaddon
        );
        array_push($accaddons, $myarr);
    }


    public function validateAccommodationPreferences($accommodations, &$accpreferences){
        foreach ($accommodations as $accommodation) {
            $myacc = Accommodation::find($accommodation['id']);
            $preferences = $myacc->savePreferences;
            foreach ($preferences as $preference) {
                if($preference->pivot->is_mandatory==true) {
                    $searched = array_search($preference->id, array_column($accpreferences, 'id'));
                    if($searched===false){
                        $this->insertAccommodationPreference($preference,$accommodation, $accpreferences);
                    }
                }
            }
        }
    }

    public function insertAccommodationPreference($preference, $accommodation, &$accpreferences){

        $price = $this->singleAccommodationPreferenceCalculation($preference->id, $accommodation['checkin'], $accommodation['checkout'],$accommodation['id']);
        $myarr = array(
            'id' => $preference->id,
            'name' => $preference->name,
            'price' => $price,
            'discount_price' => null,
            'parent_id' => $accommodation['parent_id'],
            'accommodation_id' => $accommodation['id'],
            'type' => 'accommodationpreferences',
            'checkin' => $accommodation['checkin'],
            'checkout' => $accommodation['checkout'],
            'unitno' => $accommodation['unitno'],
            'unitrem' => $accommodation['unitrem'],
            'unitname' => $accommodation['unit'],
            'is_broken' => false,
            'self' => $preference
        );
        array_push($accpreferences, $myarr);
    }

    /*******  Mandatory Items Validation End ***********/

}