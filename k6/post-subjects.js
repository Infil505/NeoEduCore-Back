import http from 'k6/http';
import { check, sleep } from 'k6';

export let options = {
    vus: 30,
    duration: '2m',
};

const token = '233|1WsKPAcZfLXxvs56EIzBV5LcttZGg8rE6G22XjBy6327459b';

export default function () {

  let random = Math.floor(Math.random() * 100000);

  let payload = JSON.stringify({
    name: "Materia_" + random
  });

  let params = {
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
    },
  };

  let res = http.post(
    'http://127.0.0.1:8000/api/subjects',
    payload,
    params
  );

  check(res, {
    'status 200 o 201': (r) => r.status == 200 || r.status == 201,
  });

  sleep(1);
}