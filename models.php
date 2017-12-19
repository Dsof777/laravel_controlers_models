<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vet extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'state_id', 'city', 'street', 'zip', 'phone', 'email'
    ];
    
    protected $appends = [
        'in_minders', 'full_name'
    ];
    
    protected $hidden = [
        'created_at', 'updated_at'
    ];
    
    protected $with = [
        'state'
    ];
    
    /* check if vet is assigned to minder */
    public function getInMindersAttribute () {
        return $this->Minders()->count() ? true : false;
    }

    public function getFullNameAttribute () {
        return $this->first_name.' '.$this->last_name;
    }
    
    public function State () {
        return $this->hasOne('App\Models\State', 'id', 'state_id');
    }
    
    public function Minders () {
        return $this->hasMany('App\Models\Minder', 'vet_id', 'id');
    }
}
====================================================================
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon;

class MonthlyPool extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'from_date',
        'to_date',
        'year',
        'month',
        'pool_fee',
        'next_challenger_num',
    ];

    /**
     * @var array
     */
    protected $dates = ['created_at', 'updated_at', 'from_date', 'to_date'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function challengers()
    {
        return $this->hasMany(Challenge::class, 'monthly_pool_id');
    }

    /**
     * @return string
     */
    public function getTitleAttribute()
    {
        return $this->from_date->format('F').' '.$this->from_date->format('Y');
    }

    /**
     * @param array $attributes
     *
     * @return static
     */
    public static function create(array $attributes = [])
    {
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        return parent::create(array_merge($attributes, [
            'from_date' => $start,
            'to_date' => $end,
            'year' => $end->format('Y'),
            'month' => $end->format('n'),
            'pool_fee' => self::getPoolFee(),
            'next_challenger_num' => 1,
        ]));
    }

    /**
     * Get current pool
     *
     * @return mixed
     */
    public static function getCurrentPool()
    {
        return self::where('from_date', '>', new Carbon('-1 month'))
            ->orderBy('from_date', 'asc')
            ->first();
    }

    /**
     * Get current pool or create new
     *
     * @return mixed
     */
    public static function getCurrentOrCreatePool()
    {
        $current_pool = self::getCurrentPool();
        if (is_null($current_pool)) {
            $current_pool = self::create();
        }

        return $current_pool;
    }

    /**
     * Get pool fee
     *
     * @return \Illuminate\Foundation\Application|mixed|null|\Tptshk\Setting\Setting
     */
    public static function getPoolFee()
    {
        if ($pool = self::getCurrentPool()) {
            return $pool->pool_fee;
        }

        return setting('pool_fee', 88.88);
    }

    /**
     * The resulting pool from the
     * current date and the specified offset
     *
     * @param int $month_offset Month count
     *
     * @return mixed
     */
    public static function getLastPool($offset = 12)
    {
        $offset = (string) (-1 * $offset);
        $date = Carbon::parse($offset.' month');

        return self::where('year', '=', $date->format('Y'))
            ->where('month', '=', $date->format('n'))
            ->first();
    }

    /**
     * Get a pool which have all completed
     * the challenge, and should start to be tested
     *
     * @return mixed
     */
    public static function getLastEndedPool()
    {
        return self::getLastPool(14);
    }

    /**
     * Receive the pool which should
     * already be known of all test results
     *
     * @return mixed
     */
    public static function getLastFinishedPool()
    {
        return self::getLastPool(15);
    }

    /**
     * Returns all outstanding pools, up to the current date
     *
     * @return mixed
     */
    public static function getAvailablePools()
    {
        return MonthlyPool::where('is_closed', '=', 0)
            ->where('from_date', '<', \Carbon::now()->format('Y-m-d H:i:s'))
            ->orderBy('from_date', 'desc')
            ->get();
    }

    /**
     * Returns all open pools
     *
     * @return mixed
     */
    public static function getOpenPools()
    {
        return MonthlyPool::where('is_closed', '=', 0)
            ->orderBy('to_date', 'asc')
            ->get();
    }

    /**
     * @param int $count
     */
    public function addChallenger($count = 1, $appointment = 'participant')
    {
        $count = (int) $count ?: 1;

        // Get only active challenges for award calculation
        $active_challengers_count = $this->getActiveChallengersCount();

        // Update number challengers for pool
        if ($appointment === 'participant') {
            $this->next_challenger_num = $this->next_challenger_num + $count;
        }

        $new_monthly_pool_amount = (float) $this->monthly_pool_amount + ($count * (float) $this->pool_fee);

        // Update pool amount and award
        $this->monthly_pool_amount = $new_monthly_pool_amount;
        if ($active_challengers_count > 0) {
            $this->monthly_pool_award = $new_monthly_pool_amount / $active_challengers_count;
        } else {
            $this->monthly_pool_award = $this->monthly_pool_amount;
        }

        $this->save();
    }

    /**
     * @param bool $strict
     */
    public function recalculateAward($strict = false)
    {
        // Get only active challenges for award calculation
        $active_challengers_count = $this->getActiveChallengersCount($strict);

        if ($active_challengers_count > 0) {
            $this->monthly_pool_award = $this->monthly_pool_amount / $active_challengers_count;
            $this->save();
        }
    }

    /**
     * @param bool $strict
     *
     * @return mixed
     */
    public function getActiveChallengers($strict = false)
    {
        return $this->getActiveChallengersQuery($strict)->get();
    }

    /**
     * @param bool $strict
     *
     * @return mixed
     */
    public function getActiveChallengersCount($strict = false)
    {
        return $this->getActiveChallengersQuery($strict)->count();
    }

    /**
     * @param bool $strict
     *
     * @return mixed
     */
    public function getActiveChallengersQuery($strict = false)
    {
        return $this->challengers()->active($strict);
    }

    /**
     * @param bool $format
     *
     * @return float|string
     */
    public function getPrize($format = true)
    {
        // Add tax
        $prize = $this->monthly_pool_award - ($this->monthly_pool_award * 0.12);

        $prize = round($prize, 2);

        if ($format === true) {
            return '$'.$prize;
        }

        return $prize;
    }
}
