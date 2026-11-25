<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

class MatchHallController extends Controller
{
    public function index(Request $request): View
    {
        $stats = DB::select(
            "SELECT
                (SELECT count(*) FROM match_profiles) AS profiles,
                (SELECT count(*) FROM match_requirements) AS requirements,
                (SELECT count(*) FROM match_offers) AS offers,
                (SELECT count(*) FROM match_records) AS records,
                (SELECT count(*) FROM match_records WHERE status = 'pending') AS pending"
        )[0];

        $profiles = DB::select(
            "SELECT id, role, name, region, grade_level, subjects, status, created_at
             FROM match_profiles ORDER BY id DESC LIMIT 50"
        );
        $requirements = DB::select(
            "SELECT r.id, p.name AS profile_name, p.role, r.title, r.region, r.grade_level, r.subjects, r.status, r.created_at
             FROM match_requirements r LEFT JOIN match_profiles p ON p.id = r.profile_id
             ORDER BY r.id DESC LIMIT 50"
        );
        $offers = DB::select(
            "SELECT o.id, p.name AS profile_name, p.role, o.title, o.region, o.grade_level, o.subjects, o.status, o.created_at
             FROM match_offers o LEFT JOIN match_profiles p ON p.id = o.profile_id
             ORDER BY o.id DESC LIMIT 50"
        );
        $matches = DB::select(
            "SELECT mc.id, r.title AS requirement, o.title AS offer, mc.score, mc.status, mc.created_at
             FROM match_records mc
             LEFT JOIN match_requirements r ON r.id = mc.requirement_id
             LEFT JOIN match_offers o ON o.id = mc.offer_id
             ORDER BY mc.score DESC NULLS LAST, mc.id DESC LIMIT 50"
        );

        $joinSubjects = function (array $rows): array {
            return array_map(function ($r) {
                $r->subjects = implode('、', json_decode($r->subjects, true) ?: []);
                return $r;
            }, $rows);
        };

        return view('admin.match-hall.index', [
            'stats' => $stats,
            'profiles' => $joinSubjects($profiles),
            'requirements' => $joinSubjects($requirements),
            'offers' => $joinSubjects($offers),
            'matches' => $matches,
        ]);
    }
}
