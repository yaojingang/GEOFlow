import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? "https://geo.youngtuo.win"),
  title: {
    default: "geo.youngtuo.win | 豆包优先的品牌共识系统",
    template: "%s | geo.youngtuo.win",
  },
  description: "面向豆包优先的 GEO 品牌共识系统：诊断品牌在 AI 答案里的位置，生成可被豆包采纳的结构化内容，并持续监测答案变化。",
  openGraph: {
    title: "geo.youngtuo.win",
    description: "让你的品牌成为豆包答案里的首选推荐。",
    url: "https://geo.youngtuo.win",
    siteName: "geo.youngtuo.win",
    locale: "zh_CN",
    type: "website",
  },
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="zh-CN">
      <body>{children}</body>
    </html>
  );
}
