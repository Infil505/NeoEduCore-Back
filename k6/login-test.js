import http from 'k6/http';
import { check, sleep } from 'k6';

export let options = {
    vus: 50,
    duration: '3m',
};

export default function () {
    let payload = JSON.stringify({
        email: "admin@neoeducore.edu.co",
        password: "password123"
    });

    let params = {
        headers: { 'Content-Type': 'application/json' },
    };

    let res = http.post(
        'http://127.0.0.1:8000/api/auth/login',
        payload,
        params
    );

    check(res, {
        'status 200': (r) => r.status === 200,
    });

    sleep(1);
}