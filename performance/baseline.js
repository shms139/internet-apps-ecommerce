import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    scenarios: {
        baseline_products: {
            executor: 'constant-vus',
            vus: 5,
            duration: '30s',
        },
    },
    summaryTrendStats: [
        'avg',
        'min',
        'med',
        'max',
        'p(90)',
        'p(95)',
        'p(99)',
    ],
    thresholds: {
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.99'],
    },
};

export default function () {
    const response = http.get(
        'http://app:8000/api/v1/products'
    );

    check(response, {
        'GET products returns 200': (r) => r.status === 200,
    });

    sleep(0.5);
}
