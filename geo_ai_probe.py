import json, sys, urllib.request, time

GW = "http://127.0.0.1:8903/chat"

def ask(q, timeout=120):
    body = json.dumps({"messages": [{"role": "user", "content": q}]}, ensure_ascii=False).encode()
    req = urllib.request.Request(GW, data=body, headers={"Content-Type": "application/json"})
    t0 = time.time()
    with urllib.request.urlopen(req, timeout=timeout) as r:
        data = json.loads(r.read().decode("utf-8", "replace"))
    return data, round((time.time() - t0) * 1000)

if __name__ == "__main__":
    q = sys.argv[1]
    d, ms = ask(q)
    print("keys:", list(d.keys()))
    print("latency_ms:", ms)
    for k in list(d.keys()):
        v = d[k]
        if isinstance(v, str):
            print(k, "len", len(v), "head:", v[:300].replace("\n", " "))
        else:
            print(k, "=", str(v)[:200])
