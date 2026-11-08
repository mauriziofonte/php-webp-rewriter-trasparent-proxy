/*
 * Fake origin of the integration harness (njs).
 *
 * Every GET or HEAD outside /__origin/ is counted (per decoded path and in
 * total), then answered after "delay" milliseconds with "status":
 *   - 200 serves the file from /srv/origin (404 when it does not exist), with
 *     the ETag, Last-Modified and conditional handling of nginx;
 *   - 301, 302, 303, 307 and 308 redirect to "location";
 *   - any other status returns a short text body.
 * The three values come from the query parameters origin_status, origin_delay
 * and origin_location of the request itself, or else from the rule stored
 * for its path through the control API. Defaults: 200, no delay.
 *
 * Control API (never counted):
 *   GET    /__origin/count[?path=/a.png]   count for one path, or the total
 *   GET    /__origin/stats                 {"total": n, "paths": {"/a.png": n}}
 *   PUT    /__origin/rules?path=/a.png&status=503&delay=200[&location=/b.png]
 *   DELETE /__origin/rules[?path=/a.png]   one rule, or all of them
 *   POST   /__origin/reset                 clears counts and rules
 */

const TOTAL = 'total';
const MAX_DELAY_MS = 60000;

function counts() {
    return ngx.shared.origin_counts;
}

function rules() {
    return ngx.shared.origin_rules;
}

function parseStatus(value) {
    if (value === undefined || value === '') {
        return 200;
    }
    const status = Number(value);
    if (!Number.isInteger(status) || status < 200 || status > 599) {
        throw new Error('status must be an integer between 200 and 599');
    }
    return status;
}

function parseDelay(value) {
    if (value === undefined || value === '') {
        return 0;
    }
    const delay = Number(value);
    if (!Number.isInteger(delay) || delay < 0 || delay > MAX_DELAY_MS) {
        throw new Error('delay must be an integer between 0 and ' + MAX_DELAY_MS + ' (milliseconds)');
    }
    return delay;
}

function behaviourOf(r) {
    const stored = rules().get(r.uri);
    const rule = stored ? JSON.parse(stored) : {};
    const pick = function (parameter, field) {
        return r.args[parameter] !== undefined ? r.args[parameter] : rule[field];
    };

    return {
        status: parseStatus(pick('origin_status', 'status')),
        delay: parseDelay(pick('origin_delay', 'delay')),
        location: pick('origin_location', 'location'),
    };
}

function sendJson(r, status, value) {
    r.headersOut['Content-Type'] = 'application/json';
    r.return(status, JSON.stringify(value) + '\n');
}

function sendText(r, status, text) {
    r.headersOut['Content-Type'] = 'text/plain; charset=utf-8';
    r.return(status, text + '\n');
}

async function serve(r) {
    if (r.method !== 'GET' && r.method !== 'HEAD') {
        r.headersOut['Allow'] = 'GET, HEAD';
        sendText(r, 405, 'Method not allowed');
        return;
    }

    counts().incr(r.uri, 1, 0);
    counts().incr(TOTAL, 1, 0);

    let behaviour;
    try {
        behaviour = behaviourOf(r);
    } catch (e) {
        sendText(r, 400, 'Invalid fake origin parameter: ' + e.message);
        return;
    }

    if (behaviour.delay > 0) {
        await new Promise(function (resolve) {
            setTimeout(resolve, behaviour.delay);
        });
    }

    if (behaviour.status === 200) {
        r.internalRedirect('@file');
        return;
    }
    if ([301, 302, 303, 307, 308].indexOf(behaviour.status) !== -1) {
        if (!behaviour.location) {
            sendText(r, 400, 'A redirect status needs a location');
            return;
        }
        r.return(behaviour.status, behaviour.location);
        return;
    }
    sendText(r, behaviour.status, 'Fake origin status ' + behaviour.status);
}

function statistics() {
    const paths = {};
    counts().items().forEach(function (entry) {
        if (entry[0] !== TOTAL) {
            paths[entry[0]] = entry[1];
        }
    });

    return { total: counts().get(TOTAL) || 0, paths: paths };
}

function control(r) {
    const action = r.method + ' ' + r.uri;
    const path = r.args.path;

    if (path !== undefined && path.charAt(0) !== '/') {
        sendText(r, 400, 'path must start with /');
        return;
    }

    switch (action) {
        case 'GET /__origin/count':
            sendText(r, 200, String(counts().get(path === undefined ? TOTAL : path) || 0));
            return;
        case 'GET /__origin/stats':
            sendJson(r, 200, statistics());
            return;
        case 'PUT /__origin/rules':
            if (path === undefined) {
                sendText(r, 400, 'path is required');
                return;
            }
            try {
                const rule = {
                    status: parseStatus(r.args.status),
                    delay: parseDelay(r.args.delay),
                    location: r.args.location,
                };
                rules().set(path, JSON.stringify(rule));
                sendJson(r, 200, rule);
            } catch (e) {
                sendText(r, 400, e.message);
            }
            return;
        case 'DELETE /__origin/rules':
            if (path === undefined) {
                rules().clear();
            } else {
                rules().delete(path);
            }
            sendText(r, 200, 'OK');
            return;
        case 'POST /__origin/reset':
            counts().clear();
            rules().clear();
            sendText(r, 200, 'OK');
            return;
        default:
            sendText(r, 404, 'Unknown control endpoint: ' + action);
    }
}

export default { serve, control };
