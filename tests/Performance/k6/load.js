/*
 * Load generator of the performance suite (k6), started by run.sh once per
 * measurement. Environment:
 *
 *   NAME          name of the measurement: the summary is written to
 *                 /results/<NAME>.json
 *   URL           URL of the requests
 *   MODE          rate   URL at RATE requests per second for DURATION
 *                        (open model: latency below saturation)
 *                 loop   URL from VUS clients back to back for DURATION
 *                        (closed model: requests per second at saturation)
 *                 miss   ITERATIONS requests one after the other, each for a
 *                        new entry: URL with "v=<RUN>-<n>" added
 *                 herd   one request from each of VUS clients, all at once
 *   EXPECT_CACHE  X-Cache-Status every response must carry (HIT, MISS), or
 *                 empty for none
 *
 * Every response must be a 200 (and carry EXPECT_CACHE): run.sh refuses a
 * measurement with a failed check.
 */

import http from 'k6/http';
import { check } from 'k6';

const NAME = __ENV.NAME;
const URL = __ENV.URL;
const MODE = __ENV.MODE;
const RUN = __ENV.RUN || '0';
const DURATION = __ENV.DURATION || '10s';
const RATE = parseInt(__ENV.RATE || '500', 10);
const VUS = parseInt(__ENV.VUS || '8', 10);
const ITERATIONS = parseInt(__ENV.ITERATIONS || '200', 10);
const EXPECT_CACHE = __ENV.EXPECT_CACHE || '';

const scenarios = {
    rate: {
        executor: 'constant-arrival-rate',
        rate: RATE,
        timeUnit: '1s',
        duration: DURATION,
        preAllocatedVUs: 128,
        maxVUs: 256,
    },
    loop: { executor: 'constant-vus', vus: VUS, duration: DURATION },
    miss: { executor: 'per-vu-iterations', vus: 1, iterations: ITERATIONS, maxDuration: '10m' },
    herd: { executor: 'per-vu-iterations', vus: VUS, iterations: 1, maxDuration: '2m' },
};

if (!NAME || !URL || !scenarios[MODE]) {
    throw new Error('NAME, URL and MODE (rate, loop, miss or herd) are required');
}

export const options = {
    scenarios: { measure: scenarios[MODE] },
    discardResponseBodies: true,
    summaryTrendStats: ['avg', 'min', 'max', 'p(50)', 'p(90)', 'p(99)'],
};

const params = {
    headers: {
        // The suite runs behind a TLS-terminating proxy that it trusts.
        'X-Forwarded-Proto': 'https',
        Accept: '*/*',
        'Accept-Encoding': 'identity',
    },
    timeout: '120s',
};

export default function () {
    let url = URL;
    if (MODE === 'miss') {
        url += (URL.indexOf('?') === -1 ? '?' : '&') + 'v=' + RUN + '-' + __ITER;
    }
    const response = http.get(url, params);
    check(response, {
        'status is 200': function (r) {
            return r.status === 200;
        },
        'cache status': function (r) {
            return EXPECT_CACHE === '' || r.headers['X-Cache-Status'] === EXPECT_CACHE;
        },
    });
}

export function handleSummary(data) {
    const metric = function (name) {
        return data.metrics[name] ? data.metrics[name].values : {};
    };
    const duration = metric('http_req_duration');
    const requests = metric('http_reqs');
    const checks = metric('checks');
    const summary = {
        name: NAME,
        mode: MODE,
        url: URL,
        requests: requests.count || 0,
        rps: requests.rate || 0,
        p50: duration['p(50)'],
        p90: duration['p(90)'],
        p99: duration['p(99)'],
        avg: duration.avg,
        max: duration.max,
        failedChecks: checks.fails || 0,
        droppedIterations: metric('dropped_iterations').count || 0,
    };
    const out = {};
    out['/results/' + NAME + '.json'] = JSON.stringify(summary) + '\n';
    out.stdout = NAME + ': ' + JSON.stringify(summary) + '\n';

    return out;
}
