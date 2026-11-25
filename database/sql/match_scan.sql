-- 撮合大厅匹配扫描：规则评分写入 match_records（幂等，可重复执行）
-- 评分：地区相同 30；学段相同 20；科目交集数 *15。
INSERT INTO match_records (requirement_id, offer_id, score, score_detail, status)
SELECT r.id AS requirement_id,
       o.id AS offer_id,
       ROUND(
           (CASE WHEN r.region = o.region THEN 30 ELSE 0 END)
         + (CASE WHEN r.grade_level = o.grade_level THEN 20 ELSE 0 END)
         + 15 * (
             SELECT count(*) FROM json_array_elements_text(r.subjects) AS rs(x)
             WHERE rs.x IN (SELECT json_array_elements_text(o.subjects)::text)
           )
       , 2) AS score,
       json_build_object(
           'region_match',  (r.region = o.region),
           'grade_match',   (r.grade_level = o.grade_level),
           'shared_subjects', (
             SELECT count(*) FROM json_array_elements_text(r.subjects) AS rs(x)
             WHERE rs.x IN (SELECT json_array_elements_text(o.subjects)::text)
           )
       ) AS score_detail,
       'pending' AS status
FROM match_requirements r
JOIN match_offers o ON o.status = 'open'
ON CONFLICT (requirement_id, offer_id) DO UPDATE
SET score        = EXCLUDED.score,
    score_detail = EXCLUDED.score_detail,
    updated_at   = CURRENT_TIMESTAMP;
