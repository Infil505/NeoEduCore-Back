<?php

namespace Tests\Feature\Routes;

use Tests\TestCase;

class ProtectedRoutesRequireAuthTest extends TestCase
{
    public function test_all_protected_routes_require_auth_and_exist(): void
    {
        // Para rutas con parámetros, ponemos IDs fake. Si da 401, perfecto (llegó al middleware).
        $endpoints = [
            ['GET',  '/api/auth/me'],
            ['POST', '/api/auth/logout'],
            ['POST', '/api/password/change'],

            ['GET',  '/api/students'],
            ['GET',  '/api/students/me'],
            ['GET',  '/api/students/1'],
            ['PUT',  '/api/students/1'],
            ['PATCH','/api/students/1/status'],

            // apiResource groups
            ['GET',  '/api/groups'],
            ['POST', '/api/groups'],
            ['GET',  '/api/groups/1'],
            ['PUT',  '/api/groups/1'],
            ['PATCH','/api/groups/1'],
            ['DELETE','/api/groups/1'],

            ['GET',  '/api/subjects'],
            ['POST', '/api/subjects'],
            ['GET',  '/api/subjects/1'],
            ['PUT',  '/api/subjects/1'],
            ['PATCH','/api/subjects/1'],
            ['DELETE','/api/subjects/1'],

            ['GET',  '/api/exams'],
            ['POST', '/api/exams'],
            ['GET',  '/api/exams/1'],
            ['PUT',  '/api/exams/1'],
            ['PATCH','/api/exams/1'],
            ['DELETE','/api/exams/1'],

            ['GET',  '/api/exams/1/questions'],
            ['POST', '/api/exams/1/questions'],
            ['PUT',  '/api/questions/1'],
            ['DELETE','/api/questions/1'],

            ['POST', '/api/exams/1/attempts/start'],
            ['POST', '/api/exams/1/attempts/1/submit'],
            ['GET',  '/api/exams/1/attempts/1'],

            ['GET',  '/api/exam-attempts/1/answers'],
            ['PATCH','/api/student-answers/1/review'],

            ['GET',  '/api/student-progress'],
            ['GET',  '/api/student-progress/me'],
            ['POST', '/api/student-progress'],
            ['POST', '/api/student-progress/recalc'],

            ['GET',  '/api/study-resources'],
            ['POST', '/api/study-resources'],
            ['GET',  '/api/study-resources/1'],
            ['PUT',  '/api/study-resources/1'],
            ['PATCH','/api/study-resources/1'],
            ['DELETE','/api/study-resources/1'],

            ['GET',  '/api/calendar-events'],
            ['POST', '/api/calendar-events'],
            ['GET',  '/api/calendar-events/1'],
            ['PUT',  '/api/calendar-events/1'],
            ['PATCH','/api/calendar-events/1'],
            ['DELETE','/api/calendar-events/1'],

            ['GET',  '/api/ai-recommendations'],
            ['GET',  '/api/ai-recommendations/me'],
            ['GET',  '/api/ai-recommendations/1'],
            ['POST', '/api/exam-attempts/1/recommendations/regenerate'],

            ['GET',  '/api/users'],
            ['GET',  '/api/users/1'],
            ['PUT',  '/api/users/1'],
            ['PATCH','/api/users/1/status'],
            ['PATCH','/api/users/1/reset-password'],

            ['GET',  '/api/institutions'],
            ['GET',  '/api/institutions/1'],
            ['PUT',  '/api/institutions/1'],
            ['PATCH','/api/institutions/1/toggle'],

            ['GET',  '/api/reports/exams/1/results'],
            ['GET',  '/api/reports/exams/1/results.csv'],
            ['GET',  '/api/reports/students/1/history'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $res = $this->json($method, $uri, []);
            $this->assertNotEquals(404, $res->status(), "Endpoint missing (404): {$method} {$uri}");
            $this->assertEquals(401, $res->status(), "Protected endpoint should be 401 without auth: {$method} {$uri}. Got {$res->status()}");
        }
    }
}