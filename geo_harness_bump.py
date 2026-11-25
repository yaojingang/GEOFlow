# -*- coding: utf-8 -*-
"""知识库内容变更后刷新检索索引：写 q_meta.data_version，让 q_engine 重建只读索引快照。
用法：python3 geo_harness_bump.py"""
import time, pymysql

c = pymysql.connect(host="127.0.0.1", port=3307, user="root", password="123456", database="qengine", autocommit=True)
cur = c.cursor()
v = str(int(time.time()))
cur.execute("SELECT v FROM q_meta WHERE k='data_version'")
if cur.fetchone():
    cur.execute("UPDATE q_meta SET v=%s, ts=%s WHERE k='data_version'", (v, int(time.time())))
else:
    cur.execute("INSERT INTO q_meta (k, v, ts) VALUES ('data_version', %s, %s)", (v, int(time.time())))
print("data_version=%s" % v)
