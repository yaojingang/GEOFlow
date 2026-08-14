<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\ContentApproval;
use App\Models\LeadForm;
use App\Models\PublicPage;
use App\Services\ZeroPoint\PublicContentWorkflow;
use Illuminate\Database\Seeder;
use RuntimeException;

class ZeroPointPublicSiteSeeder extends Seeder
{
    public function run(): void
    {
        $reviewer = Admin::query()->whereIn('role', ['super_admin', 'superadmin'])->first()
            ?? Admin::query()->first();
        if (! $reviewer) {
            throw new RuntimeException('Seed the initial admin before the Zero Point public site.');
        }

        LeadForm::query()->updateOrCreate(
            ['slug' => (string) config('zeropoint.booking_form_slug', 'zeropoint-visit-intent')],
            [
                'name' => '正负零到店预约意向',
                'status' => LeadForm::STATUS_ACTIVE,
                'description' => '仅收集到店联系与时间意向，不进行在线诊断，不自动确认预约。',
                'submit_button_label' => '提交预约意向',
                'success_message' => '预约意向已提交，工作人员会人工联系确认。',
                'fields' => [
                    ['name' => 'name', 'label' => '怎么称呼你', 'type' => 'text', 'required' => true, 'options' => []],
                    ['name' => 'phone', 'label' => '联系电话', 'type' => 'phone', 'required' => true, 'options' => []],
                    ['name' => 'wechat', 'label' => '微信号（选填）', 'type' => 'text', 'required' => false, 'options' => []],
                    ['name' => 'service_interest', 'label' => '到店意向', 'type' => 'select', 'required' => true, 'options' => ['一般咨询（暂不指定项目）']],
                    ['name' => 'preferred_date', 'label' => '期望日期', 'type' => 'date', 'required' => true, 'options' => []],
                    ['name' => 'preferred_period', 'label' => '期望时段', 'type' => 'select', 'required' => true, 'options' => ['上午 09:00—12:00', '下午 13:00—17:00', '晚间 17:00 后']],
                    ['name' => 'contact_consent', 'label' => '联系授权', 'type' => 'checkbox', 'required' => true, 'options' => ['我同意工作人员为确认本次到店意向联系我']],
                    ['name' => 'privacy_consent', 'label' => '隐私确认', 'type' => 'checkbox', 'required' => true, 'options' => ['我已了解本表仅收集预约所需的最小信息']],
                ],
            ]
        );

        $workflow = app(PublicContentWorkflow::class);
        foreach ($this->pages() as $definition) {
            $page = PublicPage::query()->firstOrCreate(
                ['slug' => $definition['slug']],
                [...$definition, 'is_placeholder' => true, 'status' => 'draft', 'version' => 1]
            );
            $page->refresh();

            foreach (ContentApproval::GATES as $gate) {
                $workflow->approve($page, $gate, $reviewer, 'approved', '本地原型占位内容，仅用于结构与交互验收；正式资料仍需对应责任人复核。');
            }

            $active = $page->activeSnapshot()->first();
            if (! $active || $active->content_hash !== $page->content_hash) {
                $workflow->publish($page, $reviewer);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function pages(): array
    {
        return [
            [
                'slug' => 'home', 'page_type' => 'home', 'area' => 'institution', 'sort_order' => 10,
                'title' => '回到真实，重新理解美与健康。', 'eyebrow' => 'ZERO POINT · XI’AN',
                'summary' => '正负零希望把选择的起点，从焦虑与推销拉回真实问题、可信证据和长期关系。',
                'body' => "## 归零，不是清空\n\n是先放下预设答案，理解你真正想解决的问题。\n\n## 溯源，不是堆砌术语\n\n是把主体、人员、流程与信息边界放到可核验的位置。\n\n## 共生，不是一笔交易\n\n是尊重你的知情、比较、拒绝与纠错权利，让每一步都能停下来重新判断。",
                'seo_title' => '正负零｜归零·溯源·共生', 'meta_description' => '正负零消费者官网本地内容原型。主体资质和服务事实确认前不进入正式索引。',
                'cta_label' => '', 'cta_url' => '',
            ],
            [
                'slug' => 'credentials', 'page_type' => 'credentials', 'area' => 'institution', 'sort_order' => 20,
                'title' => '主体与资质核验', 'eyebrow' => 'CREDENTIALS',
                'summary' => '正式信息尚待营业执照、医疗机构执业许可等原件和官方查询路径核验。',
                'body' => "## 当前公开边界\n\n本页不会使用合同简称、口头介绍或宣传材料替代法定主体与许可文件。\n\n## 正式发布前需要核对\n\n- 法定主体全称及统一社会信用代码\n- 经营地址与许可地址\n- 医疗机构执业许可证及有效期\n- 官方查询入口与最近复核日期\n\n资料未达到公开证据门槛前，这些字段保持空缺，不作推断。",
                'seo_title' => '主体与资质核验｜正负零', 'meta_description' => '查看正负零主体与资质信息的证据状态、适用范围和核验方式。',
                'cta_label' => '', 'cta_url' => '',
            ],
            [
                'slug' => 'contact', 'page_type' => 'contact', 'area' => 'institution', 'sort_order' => 30,
                'title' => '地址与联系', 'eyebrow' => 'VISIT & CONTACT',
                'summary' => '正式地址、营业时段、电话和交通指引将在责任人确认后公开。',
                'body' => "## 到店之前\n\n请以工作人员人工确认的信息为准。未经核验的地图坐标、联系电话和营业时间不会先行展示。\n\n## 联系边界\n\n网站预约只收集完成联系所需的最小信息；请不要在表单中填写病史、证件号码或影像资料。",
                'seo_title' => '地址与联系｜正负零', 'meta_description' => '正负零地址、营业时段、联系方式与到店提示。',
                'cta_label' => '提交到店意向', 'cta_url' => '/booking',
            ],
            [
                'slug' => 'team', 'page_type' => 'team', 'area' => 'institution', 'sort_order' => 40,
                'title' => '团队与职责边界', 'eyebrow' => 'PEOPLE & ROLES',
                'summary' => '人员姓名、执业信息与职责范围须经本人授权并与官方信息核验后才会展示。',
                'body' => "## 为什么暂不展示人员卡片\n\n团队介绍不是营销名单。姓名、照片、执业资格、专业范围与在岗状态属于不同事实，必须逐项确认。\n\n## 我们准备公开什么\n\n- 已授权使用的姓名与形象\n- 可核验的执业信息\n- 清晰的职责与服务边界\n- 最近复核日期和纠错入口",
                'seo_title' => '团队与职责边界｜正负零', 'meta_description' => '正负零团队人员的授权、资质、职责边界和核验说明。',
                'cta_label' => '', 'cta_url' => '',
            ],
            [
                'slug' => 'first-visit', 'page_type' => 'journey', 'area' => 'governance', 'sort_order' => 50,
                'title' => '第一次到店，会发生什么', 'eyebrow' => 'FIRST VISIT',
                'summary' => '先理解需求与边界，再决定是否继续；提交预约不等于购买或接受任何项目。',
                'body' => "## 1. 提交意向\n\n选择期望日期和时段，由工作人员人工联系。\n\n## 2. 核对安排\n\n确认地址、到店时间和必要准备；不在电话或表单中作诊断。\n\n## 3. 到店沟通\n\n先了解真实诉求、既往情况与风险边界。涉及医疗判断时，应由有资质人员完成。\n\n## 4. 自主决定\n\n你可以继续比较、暂缓或拒绝，不因提交表单而承担消费义务。",
                'seo_title' => '第一次到店流程｜正负零', 'meta_description' => '了解正负零从提交意向到人工确认、到店沟通和自主决定的流程。',
                'cta_label' => '提交到店意向', 'cta_url' => '/booking',
            ],
            [
                'slug' => 'pricing-and-receipts', 'page_type' => 'governance', 'area' => 'governance', 'sort_order' => 60,
                'title' => '价格、票据与确认', 'eyebrow' => 'PRICE & RECORDS',
                'summary' => '具体服务价格只在项目、范围、责任主体和适用条件明确后发布。',
                'body' => "## 价格公开原则\n\n不以模糊低价代替完整范围，不把咨询建议写成确定承诺。正式价格页应同时说明名称、包含内容、限制条件和有效期。\n\n## 消费前确认\n\n请在决定前核对服务主体、项目名称、金额、变更与退款条件，并保留合同、支付与票据记录。",
                'seo_title' => '价格、票据与确认｜正负零', 'meta_description' => '正负零服务价格公开原则、消费前确认事项与票据说明。',
                'cta_label' => '', 'cta_url' => '',
            ],
            [
                'slug' => 'rights-and-corrections', 'page_type' => 'governance', 'area' => 'governance', 'sort_order' => 70,
                'title' => '消费者权益、隐私与纠错', 'eyebrow' => 'RIGHTS & CORRECTIONS',
                'summary' => '你有权理解信息来源、拒绝不必要收集，并要求更正网站中的错误信息。',
                'body' => "## 你的选择权\n\n你可以在任何阶段提问、比较、暂停或拒绝。提交预约意向不构成购买承诺。\n\n## 最小必要收集\n\n预约表单只收集称呼、联系方式、一般到店意向与期望时段，不主动收集诊断、病史或身份证件。\n\n## 如何纠错\n\n正式纠错渠道将在负责人和联系方式确认后公开。收到纠错后，应保留记录、核对证据并通过新发布快照修正。",
                'seo_title' => '消费者权益、隐私与纠错｜正负零', 'meta_description' => '了解正负零的消费者选择权、隐私最小化原则和信息纠错流程。',
                'cta_label' => '', 'cta_url' => '',
            ],
            [
                'slug' => 'medical-vs-lifestyle-beauty', 'page_type' => 'health_article', 'area' => 'health', 'sort_order' => 80,
                'title' => '医疗美容与生活美容，关键区别在哪里', 'eyebrow' => 'HEALTH NOTE 01',
                'summary' => '判断一个服务属于哪一类，不能只看名称；应核对实施方式、主体资格与适用监管要求。',
                'body' => "## 先看实施方式，而不是营销名称\n\n相似的宣传词可能对应不同的操作方式与风险等级。消费者不应仅凭“护理”“管理”等称呼推断其性质。\n\n## 到店时可以问\n\n- 这项服务由什么主体提供？\n- 是否涉及侵入性操作、药品或医疗器械？\n- 谁负责判断适用性，资格如何核验？\n- 风险、替代方案和停止条件是什么？\n\n本文仅用于一般健康教育，不替代监管部门分类、执业人员判断或个体面诊。",
                'seo_title' => '医疗美容与生活美容的区别｜正负零健康知识', 'meta_description' => '从实施方式、主体资格和核验问题理解医疗美容与生活美容的边界。',
                'cta_label' => '', 'cta_url' => '',
            ],
            [
                'slug' => 'how-to-verify-a-clinic', 'page_type' => 'health_article', 'area' => 'health', 'sort_order' => 90,
                'title' => '选择机构前，可以核验哪些信息', 'eyebrow' => 'HEALTH NOTE 02',
                'summary' => '把“看起来可信”变成一组可重复的核验动作：主体、许可、人员、项目和记录。',
                'body' => "## 五个核验动作\n\n1. 核对收款、签约和提供服务的主体是否一致。\n2. 查看许可文件的名称、地址、范围和有效期。\n3. 核对实际提供判断或操作的人员身份与资格。\n4. 让项目名称、实施方式、费用和风险说明彼此对应。\n5. 保存合同、知情材料、支付记录和票据。\n\n核验不是追求零风险，而是让重要决定建立在更完整的信息上。本文不构成法律或医疗意见。",
                'seo_title' => '选择机构前如何核验信息｜正负零健康知识', 'meta_description' => '从主体、许可、人员、项目和记录五方面核验机构信息。',
                'cta_label' => '', 'cta_url' => '',
            ],
        ];
    }
}
