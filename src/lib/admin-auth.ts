import { NextResponse } from "next/server";

export function assertAdminRequest(request: Request) {
  const expected = process.env.ADMIN_CONTROL_KEY;

  if (!expected) {
    return NextResponse.json(
      {
        error: "Admin control key is not configured",
        guide: "请先在生产环境配置 ADMIN_CONTROL_KEY，再开放项目写入能力。",
      },
      { status: 503 },
    );
  }

  const actual = request.headers.get("x-geo-admin-key") ?? "";
  if (actual !== expected) {
    return NextResponse.json(
      {
        error: "Admin control key is required",
        guide: "请在设置页输入管理员控制 Key。讲解和查看功能不受影响，写入/采样/报告需要授权。",
      },
      { status: 401 },
    );
  }

  return null;
}

export function canUseAdminKey(request: Request) {
  const expected = process.env.ADMIN_CONTROL_KEY;
  return Boolean(expected && request.headers.get("x-geo-admin-key") === expected);
}
