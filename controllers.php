<?php

namespace App\Http\Controllers;

use App\Models\Challenge;
use App\Models\IncentiveBoosts;
use App\Models\Testimonial;
use App\Models\User;
use Carbon\Carbon;

class PagesController extends Controller
{
    /**
     * Main page
     *
     * @return \Illuminate\Http\Response
     */
    public function home()
    {
        return redirect()->route('dashboard');
    }

    /**
     * About us
     *
     * @return \Illuminate\Http\Response
     */
    public function aboutUs()
    {
        return $this->view([
            'content' => view('pages.about-us.about-us'),
        ]);
    }

    /**
     * Dashboard
     *
     * @return \Illuminate\Http\Response
     */
    public function dashboard()
    {
        // Get current user
        $user = \Auth::user();

        $challenge = $user->getActiveChallenge();

        if (is_null($challenge)) {
            // Not participant
            $view = 'pages.dashboard.not_participant';
            $data = $this->notParticipantDashboard($user);
        } else {
            // Participant
            $view = 'pages.dashboard.participant';
            $data = $this->participantDashboard($user, $challenge);
        }

        return $this->view([
            'content' => view($view, $data),
        ]);
    }

    /**
     * Dashboard for not participant
     *
     * @param User $user
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    protected function notParticipantDashboard(User $user)
    {
        $incentive_boosts = IncentiveBoosts::with('donator')
            ->showInSlider()
            ->take(9)
            ->orderBy('created_at', 'asc')
            ->get();

        $stories = Testimonial::with('user')
            ->approved()
            ->success()
            ->orderBy('created_at', 'asc')
            ->take(10)
            ->get();

        $show_participate_form = $user->can_participant;

        return [
            'user' => $user,
            'incentive_boosts' => $incentive_boosts,
            'stories' => $stories,
            'show_participate_form' => $show_participate_form,
        ];
    }

    /**
     * Dashboard for participant
     *
     * @param User $user
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    protected function participantDashboard(User $user, Challenge $challenge)
    {
        $start_date = $challenge->start_date->format('M. dS, Y');
        $finish_date = $challenge->finish_date->format('M. dS, Y');

        $days_in_challenge = $challenge->start_date->diffInDays(Carbon::now());
        $all_days = $challenge->start_date->diffInDays($challenge->finish_date);

        $percents = intval(100 * $days_in_challenge / $all_days);

        $incentive_boosts = $challenge->incentiveBoosts()
            ->showInSlider()
            ->take(15)
            ->orderBy('created_at', 'asc')
            ->get();

        $stories = Testimonial::with('user')
            ->approved()
            ->success()
            ->orderBy('created_at', 'asc')
            ->take(10)
            ->get();

        $share_text = 'I do not smoke '.$days_in_challenge.' days. ';
        $share_text .= 'Can you? Take part in the challenge and become a winner!';

        return [
            'user' => $user,
            'start_date' => $start_date,
            'finish_date' => $finish_date,
            'days_in_challenge' => $days_in_challenge,
            'all_days' => $all_days,
            'percents' => $percents,
            'days_in_challenge' => $days_in_challenge,
            'stories' => $stories,
            'incentive_boosts' => $incentive_boosts,
            'share_text' => $share_text,
        ];
    }

    /**
     * Donators
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function donators()
    {
        $user = \Auth::user();

        $challenge = $user->activeChallenge()->first();

        if ($challenge) {
            $source = 'your_pool';
            // Your Pool's Donators
            $donators = $user->activeChallenge();

            // Your Pool's Helpers
            $helpers = $challenge->incentiveBoosts();
        } else {
            $source = 'common';
            // Donators
            $donators = new Challenge();
            // Helpers
            $helpers = new IncentiveBoosts();
        }

        $donators = $donators->with('user')
            ->helpers()
            ->groupBy('participant_id')
            ->take(9)
            ->orderBy('created_at', 'asc')
            ->get();

        $helpers = $helpers->with('donator')
            ->showInSlider()
            ->groupBy('donator_id')
            ->take(9)
            ->orderBy('created_at', 'asc')
            ->get();

        return $this->view([
            'content' => view('pages.donators.donators', compact('source', 'donators', 'helpers')),
        ]);
    }
}

==============================================================================


<?php


namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\APIController;
use App\Http\Requests\PetRequest;
use App\Models\Pet;
use App\Models\Breed;
use App\Models\Minder;
use Illuminate\Http\Request;
use File;

class PetsController extends APIController
{
    
    public function create (PetRequest $request) {
        /* image upload */
        if (isset($request->image)) {
            $image = $this->imageUpload($request);
            /* image validation */
            if (isset($image) && $image['status'] == false && isset($image['response'])) {
               return response()->json($image['response'], 422);
            }
        }
        
        /* check breed */
        if ($request->breed_id) {
            $breed = Breed::find($request->breed_id);
            $breed_id = count($breed) ? $breed->id : null;
        } else {
            $breed_id = null;
        }

        return Pet::create([
            'user_id'      => $request->user()->id, 
            'type'         => $request->type,
            'name'         => $request->name, 
            'image'        => isset($image) && $image['status'] ? $image['path'] : '', 
            'breed_id'     => $breed_id,
            'gender'       => $request->gender, 
            'birthday'     => $request->birthday,
            'weight_range' => $request->weight_range,
            'dry_dosage' => $request->dry_dosage ?: null,
            'canned_dosage' => $request->canned_dosage ?: null,
            'dry_title' => $request->dry_title,
            'canned_title' => $request->canned_title,
        ]);
    }
    
    public function update (PetRequest $request) {
        $pet = Pet::find($request->id);

        if ($pet) {
            if ($request->image) {
                $image = $this->imageUpload($request);
                /* image validation */
                if (isset($image) && $image['status'] == false && isset($image['response'])) {
                    return response()->json($image['response'], 422);
                } else {
                    $this->removeOldImage(public_path( $pet->image ));
                }
            }
            /* reset breed when pet type changed */
            if ($request->type) {
                if ($pet->type != $request->type) {
                    $pet->breed_id = $request->breed_id > 0 ? $request->breed_id : null;
                }                
                $pet->type = $request->type;
            }
            if ($request->name) $pet->name = $request->name;
            if (isset($image) && $image['status']) $pet->image = $image['path'];
            if ($request->breed_id) $pet->breed_id = $request->breed_id > 0 ? $request->breed_id : null;
            if ($request->gender) $pet->gender = $request->gender;
            if ($request->birthday) { 
                $date = explode(' ', $request->birthday);
                $date = isset($date[2]) ? $date[2].'-'.$date[1].'-'.$date[0] : date('Y-m-d');
        
                $pet->birthday = $date; 
            }
            $pet->weight_range = $request->weight_range;
            $pet->dry_title = $request->dry_title;
            $pet->canned_title = $request->canned_title;
            $pet->dry_dosage = $request->dry_dosage ?: null ;
            $pet->canned_dosage = $request->canned_dosage ?: null;
            $pet->save();
            
            return response()->json($pet, 200);
        } else {
            return response()->json(['error'=> 'Pet not found'], 401);
        }
    }
    
    public function imageUpload($request)
    {
        $decoded_image = base64_decode($request->image);

        /* size validation */
        if (strlen($decoded_image) > PET_IMAGE_SIZE) { 
            return ['status'=>false , 'response'=> ['image'=> ['The file size should be lower than '.number_format(PET_IMAGE_SIZE / 1024000, 2) . ' MB']] ];
        }
        
        /* get mime */
        $f = finfo_open();
        $mime_type = finfo_buffer($f, $decoded_image, FILEINFO_MIME_TYPE);
        $ext = explode('/', $mime_type);
        /* type validation */
        if (isset($ext[0]) && $ext[0] != 'image') {
            return ['status'=>false , 'response'=> ['image'=> ['The file must be an image.']] ];
        }
        /* get extension */
        $ext = $ext[1];
   
        $imageName = time().'.'.$ext;
        $imagePath = 'images/pets/'.$request->user()->id.'/';
        
        if (!file_exists($imagePath)) {
            mkdir($imagePath, 0777, true);
        }
        $image = file_put_contents(public_path($imagePath.$imageName), $decoded_image);

        if ($image) {
            return ['status'=> true, 'path' => $imagePath.$imageName];
        } else {
            return ['status'=> false, 'message' => 'Error during image upload.'];
        }
    }
    
    public function removeOldImage ($image) {
        if (File::exists($image)) {
            return File::delete($image);
        } 
    }
    
    public function getById ($id) {
        $pet = Pet::find($id);
        return $pet ? $pet->toArray() : [];
    }

    public function getByUser (Request $request, $id = false) {
        $user_id = $id ?: $request->user()->id;
        
        return Pet::where(['user_id' => $user_id])->get()->toArray();
    }
    
    public function delete ($id) {
        $pet = Pet::find($id);
        
        if ($pet) {
            /* if there are minders for this pet only, remove them */
            $pet->petMinders->map(function ($item) {
                $minder_pets_count = Minder::find($item->id)->minderPets->count();

                if ($minder_pets_count < 2) {
                    $controller = app()->make('App\Http\Controllers\API\V1\MindersController');
                    app()->call([$controller, 'delete'], ['id' => $item->id, 'resp' => false]);
                }
            });
            $deleted = $pet->delete(); 
        }
        
        if (isset($deleted)) {
            return response()->json(['message'=> 'Pet deleted'], 200);
        } else {
            return response()->json(['error'=> 'Pet not found'], 401);
        }
    }
    
    public function getBreeds ($type = false) {
        return Breed::getBreeds($type);
    }
    
    public function getBreedIcon ($id) {
        $breed = Breed::find($id);
        if (isset($breed->icon) && $breed->icon) {
            header("Content-type: image/png");
            echo $breed->icon;
        } else {
            return response()->json(['error'=>'Not found'], 404);
        }
    }
}
