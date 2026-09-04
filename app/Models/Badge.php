<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use HasFactory;

    protected $primaryKey = 'badgeID';

    protected $fillable = ['uuid', 'name', 'criteriaJSON'];

    protected $casts = ['criteriaJSON' => 'array'];

    public function students()
    {
        return $this->belongsToMany(Student::class, 'student_badge', 'badgeID', 'studentID')
            ->withPivot(['triggerEvent', 'earnedAt']);
    }
}
