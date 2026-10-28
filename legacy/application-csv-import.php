<?php

require_once 'includes/config.php';


$CSV_COLUMNS = [
    'startup_name'                  => ['label' => 'Startup Name *',                   'required' => true,  'type' => 'string'],
    'contact_person'                => ['label' => 'Contact Person *',                 'required' => true,  'type' => 'string'],
    'email'                         => ['label' => 'Email *',                          'required' => true,  'type' => 'email'],
    'phone'                         => ['label' => 'Phone *',                          'required' => true,  'type' => 'string'],
    'physical_address'              => ['label' => 'Physical Address',                 'required' => false, 'type' => 'string'],
    'registration_date'             => ['label' => 'Registration Date (YYYY-MM-DD)',   'required' => false, 'type' => 'date'],
    'ursb_registered'               => ['label' => 'URSB Registered (Yes/No)',         'required' => false, 'type' => 'string'],
    'legal_status'                  => ['label' => 'Legal Status',                     'required' => false, 'type' => 'string'],
    'business_stage'                => ['label' => 'Business Stage *',                 'required' => true,  'type' => 'string'],
    'incubation_participated'       => ['label' => 'Incubation Participated (Yes/No)', 'required' => false, 'type' => 'string'],
    'incubation_programs'           => ['label' => 'Incubation Programs',              'required' => false, 'type' => 'string'],
    'founding_story'                => ['label' => 'Founding Story *',                 'required' => true,  'type' => 'text'],
    'problem_statement'             => ['label' => 'Problem Statement *',              'required' => true,  'type' => 'text'],
    'affected_population'           => ['label' => 'Affected Population *',            'required' => true,  'type' => 'string'],
    'problem_evidence'              => ['label' => 'Problem Evidence',                 'required' => false, 'type' => 'text'],
    'solution_description'          => ['label' => 'Solution Description *',           'required' => true,  'type' => 'text'],
    'uniqueness'                    => ['label' => 'Uniqueness *',                     'required' => true,  'type' => 'text'],
    'learning_outcomes_improvement' => ['label' => 'Learning Outcomes Improvement',    'required' => false, 'type' => 'text'],
    'theory_of_change'              => ['label' => 'Theory of Change *',               'required' => true,  'type' => 'text'],
    'pedagogy_approach'             => ['label' => 'Pedagogy Approach',                'required' => false, 'type' => 'text'],
    'video_link'                    => ['label' => 'Video Link (URL)',                  'required' => false, 'type' => 'string'],
    'product_stage'                 => ['label' => 'Product Stage',                    'required' => false, 'type' => 'string'],
    'website'                       => ['label' => 'Website (URL)',                     'required' => false, 'type' => 'string'],
    'demo_link'                     => ['label' => 'Demo Link (URL)',                   'required' => false, 'type' => 'string'],
    'evaluation_evidence'           => ['label' => 'Evaluation Evidence',              'required' => false, 'type' => 'text'],
    'team_members'                  => ['label' => 'Team Members *',                   'required' => true,  'type' => 'text'],
    'team_size'                     => ['label' => 'Team Size (number)',                'required' => false, 'type' => 'int'],
    'founder_names'                 => ['label' => 'Founder Names',                    'required' => false, 'type' => 'string'],
    'founder_gender'                => ['label' => 'Founder Gender',                   'required' => false, 'type' => 'string'],
    'founder_age_range'             => ['label' => 'Founder Age Range',                'required' => false, 'type' => 'string'],
    'founder_experience'            => ['label' => 'Founder Experience *',             'required' => true,  'type' => 'text'],
    'commitment_level'              => ['label' => 'Commitment Level *',               'required' => true,  'type' => 'string'],
    'advisors'                      => ['label' => 'Advisors',                         'required' => false, 'type' => 'string'],
    'traction'                      => ['label' => 'Traction *',                       'required' => true,  'type' => 'text'],
    'metrics'                       => ['label' => 'Metrics',                          'required' => false, 'type' => 'text'],
    'partnerships'                  => ['label' => 'Partnerships',                     'required' => false, 'type' => 'text'],
    'inclusion_approach'            => ['label' => 'Inclusion Approach',               'required' => false, 'type' => 'text'],
    'low_connectivity'              => ['label' => 'Low Connectivity Strategy',        'required' => false, 'type' => 'text'],
    'safeguarding'                  => ['label' => 'Safeguarding',                     'required' => false, 'type' => 'text'],
    'current_revenue_detail'        => ['label' => 'Current Revenue Detail',           'required' => false, 'type' => 'text'],
    'current_revenue'               => ['label' => 'Current Revenue (number)',          'required' => false, 'type' => 'float'],
    'funding_raised'                => ['label' => 'Funding Raised (number)',           'required' => false, 'type' => 'float'],
    'funding_sought'                => ['label' => 'Funding Sought (number)',           'required' => false, 'type' => 'float'],
    'scale_plan_8000'               => ['label' => 'Scale Plan 8000 *',                'required' => true,  'type' => 'text'],
    'scale_barriers'                => ['label' => 'Scale Barriers',                   'required' => false, 'type' => 'text'],
    'growth_vision'                 => ['label' => 'Growth Vision',                    'required' => false, 'type' => 'text'],
    'funding_use'                   => ['label' => 'Funding Use *',                    'required' => true,  'type' => 'text'],
    'funding_activities'            => ['label' => 'Funding Activities',               'required' => false, 'type' => 'text'],
    'funding_outcomes'              => ['label' => 'Funding Outcomes',                 'required' => false, 'type' => 'text'],
    'accelerator_commitment'        => ['label' => 'Accelerator Commitment *',         'required' => true,  'type' => 'text'],
    'expectations'                  => ['label' => 'Expectations',                     'required' => false, 'type' => 'text'],
];

$opportunity_id_raw = isset($_GET['opportunity_id']) ? (int)$_GET['opportunity_id'] : 0;

if (isset($_GET['action']) && $_GET['action'] === 'download_template' && $opportunity_id_raw) {
    check_role(['Administrator', 'Programs Lead', 'MEAL Lead']);

    // Discard anything already buffered (safety net)
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=applications_template_opp' . $opportunity_id_raw . '.csv');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Write UTF-8 BOM so Excel opens the file with correct encoding
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    // Row 1: human-readable labels
    ims_fputcsv($out, array_map(fn($c) => $c['label'], $CSV_COLUMNS));

    // Row 2: machine keys - the importer detects this row automatically
    ims_fputcsv($out, array_keys($CSV_COLUMNS));

   
    $sample_rows = [
        [
            'LearnBridge Uganda',
            'Sarah Nakato',
            'sarah@learnbridge.ug',
            "\t+256772100200",
            'Plot 45, Ntinda, Kampala',
            "\t2020-03-10",
            'Yes',
            'Limited Liability Company',
            'Growth',
            'Yes',
            'UNCDF FinTech Cohort 2021',
            'LearnBridge was founded in 2020 after our team spent two years researching why secondary school dropout rates in Eastern Uganda remain above 40%. We realised that learners disengage when content does not relate to their daily lives, so we built a localised digital curriculum platform.',
            'Over 1.2 million secondary school students in rural Uganda lack access to qualified teachers and relevant learning materials, leading to poor performance and high dropout rates, especially among girls.',
            '1.2 million secondary learners in Busoga, Karamoja, and West Nile sub-regions',
            'UBOS 2022 Education Census; baseline survey of 320 schools conducted March 2023',
            'LearnBridge delivers a teacher-assisted tablet platform loaded with localised, curriculum-aligned content. Each tablet works fully offline and syncs progress data when connectivity is available.',
            'Our content is co-created with local teachers and uses culturally relevant examples, unlike generic EdTech content from foreign providers. We also train teachers as facilitators rather than replacing them.',
            'Schools using LearnBridge for 6+ months report a 28% improvement in mock exam pass rates and a 35% reduction in dropout among girls.',
            'Relevant localised content engages students, improves attendance, and leads to better exam outcomes and reduced dropout.',
            'Competency-based learning with peer group activities and teacher facilitation',
            'https://vimeo.com/learnbridge-demo',
            'Pilot',
            'https://learnbridge.ug',
            'https://demo.learnbridge.ug',
            'Randomised control pilot across 12 schools in Jinja district, Q3 2023; results shared with Ministry of Education',
            'Sarah Nakato (CEO) - 8 yrs education; Moses Kiggundu (CTO) - 6 yrs software; Grace Atim (Content Lead) - 10 yrs curriculum',
            '6',
            'Sarah Nakato; Moses Kiggundu',
            'Female; Male',
            '28-35',
            'Sarah has 8 years in education programme management with USAID-funded projects. Moses led mobile development at Andela for 6 years. Grace designed NCDC-aligned curricula for 10 years.',
            'Full-time',
            'Dr. Harriet Musoke (Makerere University); James Opolot (Former NCDC Director)',
            '3,800 active student users across 18 schools; 92 trained teacher-facilitators; 6-month retention rate of 74%',
            'MAU: 3,800 | DAU: 1,150 | Avg session: 42 min | Teacher NPS: 68',
            'Aga Khan Foundation (content review); Deutsche Telekom (connectivity pilot); Uganda MoES (MOU signed)',
            'All content includes sign-language video overlays; girl-only study groups embedded in the platform design',
            'Full curriculum stored on device; 2G SMS-based progress sync as fallback; solar charging kits provided to schools',
            'Strict data minimisation policy; no student photos stored; teacher and parent consent workflows built into onboarding',
            'Hardware lease-to-own model for schools (UGX 15,000/student/term) plus government grant co-funding',
            '8500000',
            '35000000',
            '120000000',
            'Deploy to 180 schools across 4 districts by Month 12, reaching 8,000 new learners through existing district education office partnerships and a pre-agreed MOU with the Ministry.',
            'Hardware procurement lead times (6-8 weeks); teacher training capacity; school fee non-payment during COVID recovery',
            'National coverage of 500,000 learners by 2028 through a government-integrated model and district franchise approach',
            'Hardware procurement (45%), teacher training programme (25%), content localisation for 3 new regions (20%), operational costs (10%)',
            'Procure 900 tablets Q1; train 200 teachers Q2; deploy to 180 schools Q3; complete independent evaluation Q4',
            '8,000 new learners enrolled; 150 teachers certified; independent evaluation report submitted to MoES',
            'We are fully committed to the 6-month accelerator programme and will dedicate two team members full-time to the cohort activities, mentorship sessions, and reporting requirements.',
            'Access to impact investors in the East Africa EdTech space; introductions to procurement officers within MoES and UNICEF; legal support for scale contracts',
        ],
        [
            'AgroSense Technologies',
            'David Waiswa',
            'david@agrosense.co.ug',
            "\t+256701334455",
            '15 Luthuli Avenue, Bugolobi, Kampala',
            "\t2019-11-22",
            'Yes',
            'Private Limited Company',
            'Scale',
            'Yes',
            'Regional Agri-Tech Accelerator 2020; GIZ Make-IT Africa 2021',
            'AgroSense was started by David Waiswa and two agronomists after witnessing smallholder farmers in Mbale lose 30-50% of their maize harvests annually due to undetected soil nutrient deficiencies and poor timing of inputs. We built affordable IoT soil sensors and paired them with an SMS advisory service accessible on any phone.',
            'Over 4 million smallholder farmers in Uganda lack access to affordable, real-time soil data, forcing them to apply fertilisers blindly. This leads to over-application in some areas and under-application in others, costing farmers an estimated UGX 200 billion annually in wasted inputs and lost yield.',
            '4.2 million smallholder farmers across Eastern and Northern Uganda',
            'FAO 2023 Uganda Agri-Productivity Report; AgroSense baseline survey of 800 farms in Mbale and Soroti (2022)',
            'AgroSense offers a low-cost IoT soil sensor (UGX 45,000) that measures NPK, pH, moisture, and temperature. Farmers receive weekly SMS recommendations in Luganda or Ateso based on their soil readings, enabling precise input application and harvest timing.',
            'Our sensor costs 80% less than imported alternatives by using locally sourced components assembled in Kampala. Competitors require smartphones; ours works entirely via SMS on a basic feature phone.',
            'Farmers using AgroSense for one full growing season report an average yield increase of 34% and input cost savings of 22%.',
            'Accurate soil data leads to precise input use, which increases yields, reduces costs, and improves farmer income, contributing to national food security.',
            'Problem-based extension advisory via SMS; peer farmer demo groups of 10',
            'https://youtu.be/agrosense-pilot',
            'Growth',
            'https://agrosense.co.ug',
            '',
            'Season-long controlled trial across 400 farms in Mbale district comparing AgroSense users vs control group, Q4 2022. Published findings shared with MAAIF.',
            'David Waiswa (CEO/Agronomist); Lydia Aber (CTO/Electronics Engineer); Patrick Mugisha (Head of Field Operations)',
            '8',
            'David Waiswa; Lydia Aber',
            'Male; Female',
            '30-40',
            'David holds an MSc Agronomy from Makerere and 9 years of field extension experience. Lydia has 7 years in embedded systems engineering. Patrick managed field operations for Bayer Crop Science Uganda for 5 years.',
            'Full-time',
            'Prof. Michael Ocen (Gulu University - Agronomy); Rose Nakibuule (Former MAAIF Director)',
            '12,400 registered farmer households; 640 active agro-dealer resellers; 3 district local government partnerships; UGX 485 million in cumulative farmer income uplift documented',
            'Active farmers: 12,400 | Sensors deployed: 9,800 | Avg yield uplift: +34% | Input cost saving: -22% | SMS advisory open rate: 81%',
            'MAAIF (data sharing MOU); Yara Uganda (fertiliser recommendation integration); BRAC Uganda (farmer group channel)',
            'Gender-disaggregated advisory SMS; women farmer group priority enrolment; content available in 4 local languages',
            'Full SMS fallback; USSD advisory menu for zero-data environments; sensor data cached locally for 30 days',
            'Child labour policy enforced across all partner farmer groups; safe agro-chemical handling guidelines included in all advisories',
            'Hardware sales (UGX 45,000/sensor) + UGX 5,000/month advisory subscription',
            '72000000',
            '85000000',
            '350000000',
            'Expand to 8,000 additional farmer households across 3 new districts (Lira, Gulu, Soroti) by Month 10 using existing agro-dealer and district government channels already contracted.',
            'Sensor component supply chain disruptions; seasonal cash flow constraints among smallholder clients; last-mile delivery in remote areas',
            'Reach 100,000 farmer households across Uganda by 2027 and expand into Kenya and Tanzania via an East Africa agro-dealer franchise model',
            'Sensor component inventory for 10,000 units (40%); field officer expansion - hire 12 new agronomists (30%); marketing and farmer onboarding (20%); working capital (10%)',
            'Procure sensor components Q1; hire and train 12 field officers Q2; onboard 8,000 farmers across 3 districts Q2-Q3; complete season evaluation Q4',
            '8,000 new farmer households enrolled; 34% average yield uplift documented; 3 new district government partnerships formalised',
            'Two senior team members will attend all cohort sessions and dedicate 60% of their time to accelerator activities. We will share quarterly impact data openly with the cohort.',
            'Patient capital investor introductions; legal support for cross-border expansion; connections to MAAIF and development finance institutions such as AfDB',
        ],
        [
            'MamaHealth Connect',
            'Florence Akullo',
            'florence@mamahealth.ug',
            "\t+256783221100",
            'Acacia Mall, 3rd Floor, Kololo, Kampala',
            "\t2021-07-05",
            'Yes',
            'Non-Governmental Organisation',
            'Early Stage',
            'No',
            '',
            'Florence Akullo founded MamaHealth Connect after losing her younger sister to a preventable postpartum complication in Apac district in 2019. The community health worker who visited the home had no digital tools and no way to flag the deteriorating vital signs. Florence resolved to build a simple tool that community health workers could use on any phone.',
            'Uganda has a maternal mortality ratio of 336 per 100,000 live births. Over 70% of these deaths occur in rural areas and are caused by conditions detectable by trained community health workers - including postpartum haemorrhage, sepsis, and eclampsia - yet CHWs use paper-based registers and have no escalation protocol.',
            '1.8 million women of childbearing age in rural Northern and Eastern Uganda served by 22,000 community health workers',
            'Uganda Demographic and Health Survey 2022; MOH Annual Health Sector Performance Report 2023',
            'MamaHealth Connect is a USSD and SMS-based tool that guides community health workers through structured postnatal assessment checklists and automatically escalates high-risk cases to the nearest health facility via an SMS alert. No smartphone or internet required.',
            'Unlike paper checklists, our tool triggers automatic escalation and records timestamped data for district health audits. It integrates directly with the DHISv2 reporting system already used by the Ministry of Health.',
            'Early pilot data from 3 sub-counties in Apac shows a 41% reduction in delayed referrals and a 28% improvement in postnatal visit completion rates.',
            'Structured CHW assessments detect risk early, trigger timely referrals, and reduce preventable maternal and newborn deaths.',
            'Task-shifting to CHWs using structured assessment protocols; community-based group education sessions for pregnant women',
            '',
            'Pilot',
            'https://mamahealth.ug',
            '',
            'Three-month pilot in Apac district (Oyam sub-county): 180 CHWs, 640 postnatal assessments. Data validated by district health officer.',
            'Florence Akullo (Executive Director); Dr. Kenneth Omara (Medical Officer/Co-founder); Angella Aber (Technology Lead)',
            '5',
            'Florence Akullo; Dr. Kenneth Omara',
            'Female; Male',
            '27-38',
            'Florence has 7 years in public health programme management with IRC and MSF. Dr. Omara is a registered medical officer with 6 years in maternal and child health. Angella has 5 years in USSD and mobile application development.',
            'Full-time',
            'Dr. Juliet Kiguli (Makerere School of Public Health); Immaculate Apio (MOH RMNCAH Desk)',
            '640 postnatal assessments completed; 180 CHWs trained and active; 23 high-risk referrals escalated and confirmed resolved; district health officer formally endorsing expansion',
            'CHW assessments: 640 | Referral escalation rate: 3.6% | Referral resolution confirmed: 100% | Average assessment time: 8 minutes',
            'Apac District Health Office (MOU); UNICEF Uganda (technical review); Amref Health Africa (joint funding application submitted)',
            'All content available in Luo and Ateso; CHW recruitment prioritises women; digital literacy module included in all training',
            'Fully functional on USSD and SMS with zero data requirements; works on 2G feature phones across all network operators',
            'Child safeguarding policy in place; all CHWs sign confidentiality agreements; no patient-identifiable data stored outside district health server',
            'Per-assessment government reimbursement model (MOH HSSIP-funded at UGX 3,500 per assessment) being negotiated',
            '0',
            '18000000',
            '95000000',
            'Deploy to 800 CHWs across 4 districts (Apac, Lira, Gulu, Kitgum) using the district health officer endorsement pathway already piloted in Apac. Reach 8,000 postnatal assessments in Month 1-8.',
            'MOH procurement timelines; CHW phone ownership gaps in some sub-counties; district health officer turnover',
            'National coverage across all 135 districts by 2027, integrated as a standard tool within the MOH community health strategy',
            'CHW training and onboarding (35%); USSD platform scale and maintenance (25%); monitoring and evaluation (20%); personnel (20%)',
            'Train 800 CHWs across 4 districts Q1-Q2; reach 8,000 assessments Q3; complete independent evaluation Q4; submit MOH national rollout proposal Q4',
            '8,000 postnatal assessments logged; 800 CHWs trained; independent evaluation report; MOH national rollout proposal submitted',
            'We are fully committed to the accelerator programme. Florence and Kenneth will attend all sessions. We see the accelerator as critical to securing the MOH endorsement and impact investor funding we need to scale nationally.',
            'Connections to MOH RMNCAH directorate decision-makers; introductions to impact investors with a health focus; legal and governance support for NGO-to-social enterprise transition',
        ],
        [
            'SkillForge Vocational',
            'Robert Tumusiime',
            'robert@skillforge.ug',
            "\t+256756889900",
            '7 Bombo Road, Kawempe, Kampala',
            "\t2022-01-18",
            'No',
            'Sole Proprietorship',
            'Idea / Pre-Revenue',
            'No',
            '',
            'Robert Tumusiime spent three years as a BTVET instructor and watched hundreds of graduates struggle to find work not because they lacked skills but because employers had no way to verify what they could actually do. He started SkillForge to replace paper certificates with verified digital skills portfolios that employers can trust.',
            'Over 200,000 Ugandan youth graduate from vocational and BTVET institutions each year holding paper certificates that employers cannot verify and that do not demonstrate practical competency. Youth unemployment among BTVET graduates stands at 62% within two years of graduation.',
            '200,000 annual BTVET graduates and the 350,000 employers who could hire them',
            'MGLSD Labour Market Survey 2023; SkillForge employer survey of 120 SMEs in Kampala and Mbarara (2024)',
            'SkillForge issues blockchain-verified digital skills badges to BTVET graduates. Each badge is linked to a video evidence portfolio (assessed practical task recordings) and can be shared via WhatsApp or a QR code on a printed card for employers without smartphones.',
            'We are the only provider in Uganda combining blockchain verification with video evidence portfolios accessible via QR code for low-tech employers. Competitors issue PDF certificates with no verification layer.',
            'Graduates with a SkillForge portfolio are 2.5x more likely to receive an interview callback within 30 days compared to those presenting paper certificates only (based on our 90-day pilot with 3 Kampala employers).',
            'Verifiable skills evidence builds employer trust, reduces hiring friction, and connects qualified graduates to employment faster.',
            'Competency-based assessment with video evidence; peer and supervisor validation',
            'https://youtu.be/skillforge-intro',
            'Prototype',
            'https://skillforge.ug',
            'https://app.skillforge.ug/demo',
            '90-day employer pilot with Roofings Group, Movit Products, and a KCCA-registered construction firm. 3 hires made directly from SkillForge portfolios.',
            'Robert Tumusiime (Founder/CEO); Patricia Nantaba (Product/UX); Ivan Ssemwanga (Blockchain Developer)',
            '3',
            'Robert Tumusiime',
            'Male',
            '31',
            'Robert has 3 years as a BTVET instructor and 2 years in curriculum design for UGAPRIVI. Patricia has 4 years in UX design for mobile-first African markets. Ivan has 3 years in Ethereum and Hyperledger development.',
            'Part-time (moving to full-time at funding)',
            'Dr. Consolata Kabonesa (Makerere - Education Policy); Charles Mugoya (PSFU Skills Development Committee)',
            '480 graduates issued digital portfolios; 3 employer partners actively using the verification portal; 3 confirmed hires traceable to SkillForge portfolios; 12 BTVET institutions expressing interest',
            'Portfolios issued: 480 | Employer verifications: 210 | Confirmed hires: 3 | Employer NPS: 72 | Average time-to-hire reduction: 18 days',
            'Uganda Gatsby Trust (letters of support); PSFU (employer access); Directorate of Industrial Training (curriculum alignment review)',
            '50% of pilot cohort are young women; content available in Luganda; USSD verification option for feature phone employers',
            'QR code verification works fully offline for employers; WhatsApp badge sharing requires only basic 2G data',
            'Video evidence stored on encrypted servers; graduates own their data and control who can view their portfolio; child protection policy covers under-18 apprentices',
            'Institution subscription (UGX 150,000/institution/year) + employer verification API (UGX 500/verification)',
            '0',
            '5000000',
            '80000000',
            'Sign 40 BTVET institutions in 5 regions and issue 8,000 verified portfolios by Month 9 using the Directorate of Industrial Training endorsement pathway currently under negotiation.',
            'BTVET institution ICT readiness; student smartphone access for video recording; employer behaviour change',
            'Become the national digital credentials infrastructure for Uganda\'s BTVET sector by 2027 and expand to Kenya and Rwanda',
            'Product development - video compression and USSD layer (40%); sales and institution onboarding (30%); personnel - hire 2 full-time staff (20%); legal - IP and data protection (10%)',
            'Complete USSD verification layer Q1; sign 40 institutions Q2; issue 8,000 portfolios Q3; complete employer impact study Q4',
            '8,000 verified portfolios issued; 40 institutions onboarded; employer impact study published; DIT national endorsement secured',
            'Robert and Patricia will attend every accelerator session and commit full-time upon funding release. We are specifically seeking the network and credibility the accelerator provides to close the DIT endorsement.',
            'Introductions to MGLSD and DIT decision-makers; impact investor connections; legal support for data protection compliance and IP registration',
        ],
        [
            'WasteWorth Uganda',
            'Immaculate Nambogo',
            'immaculate@wasteworthug.com',
            "\t+256704567123",
            'Industrial Area, Namanve, Kampala',
            "\t2020-09-30",
            'Yes',
            'Limited Liability Company',
            'Growth',
            'Yes',
            'Unreasonable East Africa 2022; Village Capital Climate 2023',
            'Immaculate Nambogo grew up in Bwaise, one of Kampala\'s most flood-prone informal settlements, where blocked drainage channels filled with plastic waste caused seasonal flooding that destroyed homes and livelihoods. After completing her environmental engineering degree, she returned to Bwaise to build a community plastic collection network that converts waste into construction materials.',
            'Kampala generates 1,500 tonnes of solid waste daily, of which 22% is plastic. Less than 8% is formally collected, leaving the rest in drainage channels, waterways, and open dumps, driving flooding, disease, and environmental degradation. Informal waste pickers earn less than UGX 5,000 per day with no market certainty.',
            '2.5 million Kampala residents exposed to flooding and health risks; 14,000 informal waste pickers with no stable income',
            'NEMA Uganda Solid Waste Assessment 2022; KCCA Drainage Master Plan 2023; WasteWorth baseline survey of 800 waste pickers (2023)',
            'WasteWorth operates community plastic collection points in 6 Kampala parishes, aggregating plastic from 1,200 registered waste pickers. Plastic is processed into interlocking paving tiles and roofing sheets sold to construction companies and KCCA for public infrastructure.',
            'We close the full loop - collection, processing, and sales - within a 15 km radius, eliminating transport cost barriers that prevent other recyclers from being viable. Our waste picker registration and digital payment system is the first in Uganda to provide income traceability for informal workers.',
            'WasteWorth construction tiles have been adopted by KCCA in two pilot road resurfacing projects. Life-cycle cost analysis shows 30% lower maintenance cost vs conventional materials.',
            'Formalised plastic collection improves drainage, reduces flood risk, increases waste picker income, and produces affordable construction materials, creating a circular economy loop.',
            'Community-led collection model; cooperative ownership structure for waste pickers',
            'https://vimeo.com/wasteworthug',
            'Growth',
            'https://wasteworthug.com',
            'https://wasteworthug.com/tiles',
            'KCCA Pilot Road Resurfacing Report, 2023; independent life-cycle cost assessment by Makerere Engineering Department',
            'Immaculate Nambogo (CEO/Environmental Engineer); Denis Ssali (Operations Director); Winnie Apio (Community Relations Manager)',
            '9',
            'Immaculate Nambogo',
            'Female',
            '29',
            'Immaculate holds a BEng Environmental Engineering from Makerere and 5 years in waste management programme design with NEMA and UN-Habitat. Denis has 8 years in manufacturing operations. Winnie has 6 years in community mobilisation with CARE International.',
            'Full-time',
            'Eng. Paul Byarugaba (NEMA Board); Dr. Sara Namirembe (UN-Habitat Uganda Country Director)',
            '1,200 registered waste pickers earning an average UGX 28,000/day (up from UGX 5,000); 85 tonnes of plastic collected monthly; 42,000 sq metres of paving tiles sold; KCCA supply agreement signed',
            'Plastic collected/month: 85 tonnes | Tiles produced/month: 42,000 sq m | Active waste pickers: 1,200 | Avg picker daily income: UGX 28,000 | KCCA supply contract value: UGX 380M/year',
            'KCCA (supply contract); UN-Habitat (technical partnership); Stanbic Bank Uganda (waste picker mobile money payments integration)',
            'Women-only collection point leadership; picker income supplement during low-season months; disability-inclusive collection point design',
            'Collection points are physical sites requiring no connectivity; digital payment fallback via USSD mobile money for pickers without smartphones',
            'Child labour prohibition strictly enforced at all collection points; occupational health and safety training for all pickers; PPE provided',
            'Tile and sheet sales to construction companies and KCCA (UGX 8,500/sq m); waste picker membership fee (UGX 2,000/month includes PPE and mobile money wallet)',
            '145000000',
            '120000000',
            '400000000',
            'Expand from 6 to 18 parishes across 3 Kampala divisions by Month 10, adding 2,400 new waste pickers and increasing plastic collection capacity to 220 tonnes/month, sufficient to fulfil the expanded KCCA supply contract already in principle agreed.',
            'Production machinery scale-up lead time (12 weeks); working capital for picker advance payments during scale-up; KCCA procurement approval timelines',
            'Operate in all 5 Kampala divisions and expand to Jinja and Mbarara by 2027; list waste picker cooperative shares on the Uganda Securities Exchange social bond market',
            'Machinery expansion - 2 additional extrusion lines (45%); working capital for picker advance payments (25%); community onboarding and training (20%); monitoring and certification (10%)',
            'Install 2 new extrusion lines Q1; onboard 2,400 new waste pickers across 12 new parishes Q2-Q3; deliver 220 tonnes/month production Q4; complete ISO 14001 certification audit Q4',
            '2,400 new waste pickers registered and earning above UGX 20,000/day; 220 tonnes/month plastic processed; expanded KCCA contract fulfilled; ISO 14001 certified',
            'All three co-founders are 100% committed to the accelerator. We will prioritise every session and milestone. The accelerator is our primary pathway to the blended finance facility we need to close the machinery financing gap.',
            'Introductions to development finance institutions (AfDB, IFC, EADB) for machinery financing; legal support for cooperative share issuance; connections to KCCA procurement officers and national government infrastructure funds',
        ],
    ];

    foreach ($sample_rows as $row) {
        ims_fputcsv($out, $row);
    }

    fclose($out);
    exit();
}

// ---------------------------------------------------------------
// Normal page flow starts here - safe to buffer and include HTML
// ---------------------------------------------------------------
ob_start();

$page_title = 'CSV Import - Applications';
require_once 'includes/header.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead']);

// ---------------------------
// Opportunity guard
// ---------------------------
$opportunity_id = $opportunity_id_raw;
if (!$opportunity_id) {
    $_SESSION['error'] = "Invalid opportunity ID.";
    header("Location: application-opportunities");
    exit();
}

$opp_stmt = $conn->prepare("SELECT * FROM application_opportunities WHERE opportunity_id = ?");
$opp_stmt->bind_param("i", $opportunity_id);
$opp_stmt->execute();
$opp_result = $opp_stmt->get_result();
if (!$opp_result || $opp_result->num_rows === 0) {
    $_SESSION['error'] = "Opportunity not found.";
    header("Location: application-opportunities");
    exit();
}
$opportunity = $opp_result->fetch_assoc();
$opp_stmt->close();

// ---------------------------------------------------------------
// Helper: normalise a cell value that Excel may have corrupted
//
// Excel corruptions we fix here:
//   1. Phone/ID numbers with leading + become scientific notation
//      e.g. +256700000000 -> 2.567E+11
//      Fix: detect E+/E- pattern, convert back via number_format
//   2. Dates get reformatted to regional format
//      e.g. 2021-06-15 -> 15/06/2021 or 6/15/2021
//      Fix: try multiple date parse patterns, normalise to YYYY-MM-DD
//   3. Tab prefix we wrote into the template gets preserved as \t
//      Fix: strip leading whitespace (ltrim)
// ---------------------------------------------------------------
function fix_excel_cell(string $val, string $type): string {
    $val = ltrim($val);   // strip leading tab (our Excel text-force trick)

    if ($type === 'string' || $type === 'email') {
        // Reverse scientific notation for phone numbers / IDs
        // Matches: 2.567E+11  or  2.567e+11  or  256700000000.0
        if (preg_match('/^[\d.]+[eE][+\-]\d+$/', $val)) {
            $num = (int)round((float)$val);
            return '+' . $num;
        }
    }

    if ($type === 'date' && $val !== '') {
        // Already ISO format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;

        // d/m/Y  (most common Excel regional format)
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        // m/d/Y  (US Excel format - ambiguous, assume d <= 12 means d/m/Y first)
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $val, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        // Try PHP's strtotime as last resort
        $ts = strtotime($val);
        if ($ts !== false) return date('Y-m-d', $ts);
    }

    return $val;
}

// ---------------------------------------------------------------
// ACTION: Process Upload
// ---------------------------------------------------------------
$import_results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {

    if (
        empty($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $_SESSION['error'] = "Invalid form token.";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
    // Do not rotate $_SESSION['csrf_token'] here: it is the shared per-session
    // token used by every form (includes/security.php).

    $import_status = $_POST['import_status'] ?? 'Submitted';
    if (!in_array($import_status, ['Draft', 'Submitted', 'Under Review'])) {
        $import_status = 'Submitted';
    }

    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "File upload error: " . $file['error'];
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $_SESSION['error'] = "Only .csv files are accepted.";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $_SESSION['error'] = "Could not open uploaded file.";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }

    // ---------------------------------------------------------------
    // DELIMITER AUTO-DETECTION
    //
    // Excel saves CSVs with commas in English locales but tabs or
    // semicolons in many other regional settings. We sniff the first
    // non-empty line and pick whichever delimiter produces the most
    // columns, since the template has 51 columns.
    // ---------------------------------------------------------------
    $sniff_line = '';
    while ($sniff_line === '' && !feof($handle)) {
        $sniff_line = rtrim(fgets($handle), "\r\n");
    }
    rewind($handle);  // reset so fgetcsv reads from the beginning

    $delimiters = [
        ','  => substr_count($sniff_line, ','),
        "\t" => substr_count($sniff_line, "\t"),
        ';'  => substr_count($sniff_line, ';'),
        '|'  => substr_count($sniff_line, '|'),
    ];
    arsort($delimiters);
    $delim = (string)array_key_first($delimiters);

    // ---------------------------------------------------------------
    // HEADER DETECTION (dual-buffer)
    //
    // $raw_buf  = untouched rows from fgetcsv - used as data values
    // $norm_buf = BOM-stripped + lowercased - used only for key matching
    //
    // We scan up to 10 rows and pick the one with the most matches
    // against our known column keys as the header row.
    // Raw rows after the key row become the first data rows.
    // ---------------------------------------------------------------
    $valid_keys    = array_keys($CSV_COLUMNS);
    $valid_keys_lc = array_map('strtolower', $valid_keys);
    $lc_to_key     = array_combine($valid_keys_lc, $valid_keys);

    $raw_buf      = [];
    $norm_buf     = [];
    $best_idx     = -1;
    $best_matches = 0;

    for ($i = 0; $i < 10; $i++) {
        $raw = fgetcsv($handle, 0, $delim);
        if ($raw === false) break;

        $raw_buf[] = $raw;

        $normed = [];
        foreach ($raw as $cell) {
            $cell     = str_replace("\xEF\xBB\xBF", '', $cell); // UTF-8 BOM
            $cell     = str_replace("\xFF\xFE",     '', $cell); // UTF-16 LE
            $cell     = str_replace("\xFE\xFF",     '', $cell); // UTF-16 BE
            $normed[] = strtolower(trim($cell));
        }
        $norm_buf[] = $normed;

        $matches = count(array_intersect($normed, $valid_keys_lc));
        if ($matches > $best_matches) {
            $best_matches = $matches;
            $best_idx     = $i;
        }
    }

    if ($best_matches >= 1) {
        $key_row      = array_map(fn($v) => $lc_to_key[$v] ?? $v, $norm_buf[$best_idx]);
        $data_queue   = array_slice($raw_buf, $best_idx + 1);  // raw rows after key row
        $rows_scanned = $best_idx + 1;
    } else {
        $key_row      = $valid_keys;   // no header - positional fallback
        $data_queue   = $raw_buf;
        $rows_scanned = 0;
    }

    $required_keys  = array_keys(array_filter($CSV_COLUMNS, fn($c) => $c['required']));
    $inserted       = 0;
    $skipped        = 0;
    $sample_skipped = 0;   // rows skipped because they are built-in sample rows
    $errors         = [];
    $row_num        = $rows_scanned;
    $queue_pos      = 0;
    $now            = date('Y-m-d H:i:s');
    $current_user   = (int)($_SESSION['user_id'] ?? 1);

    // Drain buffered queue first, then read rest from file handle
    while (true) {
        if ($queue_pos < count($data_queue)) {
            $row = $data_queue[$queue_pos++];   // RAW - original case
        } else {
            $row = fgetcsv($handle, 0, $delim);
            if ($row === false) break;
        }

        $row_num++;

        // Skip blank rows
        if (count(array_filter($row, fn($v) => trim($v) !== '')) === 0) continue;

        // Map keys to raw cell values
        $data = [];
        foreach ($key_row as $idx => $key) {
            if (in_array($key, $valid_keys)) {
                $raw_val       = isset($row[$idx]) ? trim($row[$idx]) : '';
                $data[$key]    = fix_excel_cell($raw_val, $CSV_COLUMNS[$key]['type']);
            }
        }

        // Skip the built-in template example rows (rows 3-7)
        $example_emails = [
            'sarah@learnbridge.ug',
            'david@agrosense.co.ug',
            'florence@mamahealth.ug',
            'robert@skillforge.ug',
            'immaculate@wasteworthug.com',
        ];
        if (in_array(($data['email'] ?? ''), $example_emails)) {
            $sample_skipped++;
            $skipped++;
            continue;
        }

        // Fill missing keys with empty string
        foreach ($valid_keys as $k) {
            if (!isset($data[$k])) $data[$k] = '';
        }

        // Validate required fields
        $row_errors = [];
        foreach ($required_keys as $rk) {
            if (empty($data[$rk])) {
                $row_errors[] = "Missing: {$CSV_COLUMNS[$rk]['label']}";
            }
        }
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $row_errors[] = "Invalid email: {$data['email']}";
        }
        if (!empty($row_errors)) {
            $errors[] = "Row $row_num: " . implode('; ', $row_errors);
            $skipped++;
            continue;
        }

        // Duplicate check
        $dup = $conn->prepare(
            "SELECT application_id FROM applications
              WHERE opportunity_id = ? AND email = ? AND status != 'Draft' LIMIT 1"
        );
        $dup->bind_param("is", $opportunity_id, $data['email']);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $errors[] = "Row $row_num: Duplicate - {$data['email']} already applied.";
            $skipped++;
            $dup->close();
            continue;
        }
        $dup->close();

        // Get or create user
        $usr = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $usr->bind_param("s", $data['email']);
        $usr->execute();
        $usr_res = $usr->get_result();
        if ($usr_res->num_rows > 0) {
            $submitted_by = (int)$usr_res->fetch_assoc()['user_id'];
        } else {
            $hp  = password_hash(bin2hex(random_bytes(6)), PASSWORD_DEFAULT);
            $inu = $conn->prepare(
                "INSERT INTO users (username, email, password_hash, full_name, role, is_active)
                 VALUES (?, ?, ?, ?, 'Applicant', 1)"
            );
            $inu->bind_param("ssss", $data['email'], $data['email'], $hp, $data['contact_person']);
            $inu->execute();
            $submitted_by = (int)$inu->insert_id;
            $inu->close();
        }
        $usr->close();

        // Type-cast field values
        $cast = function(string $key, $val) use ($CSV_COLUMNS) {
            $type = $CSV_COLUMNS[$key]['type'];
            if ($val === '' || $val === null) return null;
            return match ($type) {
                'int'   => (int)$val,
                'float' => (float)$val,
                'date'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : null,
                default => (string)$val,
            };
        };

        $d = [];
        foreach ($valid_keys as $k) {
            $d[$k] = $cast($k, $data[$k]);
        }

        // Named variables - MySQLi bind_param requires real variable references
        $b_opp_id          = $opportunity_id;
        $b_startup         = (string)($d['startup_name']             ?? '');
        $b_reg_date        = $d['registration_date']        ?: null;
        $b_ursb            = $d['ursb_registered']          ?: null;
        $b_legal           = $d['legal_status']             ?: null;
        $b_stage           = (string)($d['business_stage']           ?? '');
        $b_incub_part      = $d['incubation_participated']  ?: null;
        $b_incub_prog      = $d['incubation_programs']      ?: null;
        $b_founding        = (string)($d['founding_story']           ?? '');
        $b_problem         = (string)($d['problem_statement']        ?? '');
        $b_affected        = (string)($d['affected_population']      ?? '');
        $b_prob_ev         = $d['problem_evidence']         ?: null;
        $b_solution        = (string)($d['solution_description']     ?? '');
        $b_unique          = (string)($d['uniqueness']               ?? '');
        $b_lo_improve      = $d['learning_outcomes_improvement'] ?: null;
        $b_toc             = (string)($d['theory_of_change']         ?? '');
        $b_pedagogy        = $d['pedagogy_approach']        ?: null;
        $b_video           = $d['video_link']               ?: null;
        $b_prod_stage      = $d['product_stage']            ?: null;
        $b_website         = $d['website']                  ?: null;
        $b_demo            = $d['demo_link']                ?: null;
        $b_eval_ev         = $d['evaluation_evidence']      ?: null;
        $b_team_members    = (string)($d['team_members']             ?? '');
        $b_team_size       = ($d['team_size'] !== null) ? (int)$d['team_size'] : null;
        $b_founder_names   = $d['founder_names']            ?: null;
        $b_founder_gender  = $d['founder_gender']           ?: null;
        $b_founder_age     = $d['founder_age_range']        ?: null;
        $b_founder_exp     = (string)($d['founder_experience']       ?? '');
        $b_commitment      = $d['commitment_level']         ?: null;
        $b_advisors        = $d['advisors']                 ?: null;
        $b_contact         = (string)($d['contact_person']           ?? '');
        $b_email           = (string)($d['email']                    ?? '');
        $b_phone           = (string)($d['phone']                    ?? '');
        $b_address         = $d['physical_address']         ?: null;
        $b_traction        = (string)($d['traction']                 ?? '');
        $b_metrics         = $d['metrics']                  ?: null;
        $b_partnerships    = $d['partnerships']             ?: null;
        $b_inclusion       = $d['inclusion_approach']       ?: null;
        $b_low_conn        = $d['low_connectivity']         ?: null;
        $b_safeguard       = $d['safeguarding']             ?: null;
        $b_rev_detail      = $d['current_revenue_detail']   ?: null;
        $b_cur_rev         = (float)($d['current_revenue']  ?? 0);
        $b_fund_raised     = (float)($d['funding_raised']   ?? 0);
        $b_fund_sought     = (float)($d['funding_sought']   ?? 0);
        $b_scale_plan      = (string)($d['scale_plan_8000']          ?? '');
        $b_scale_barriers  = $d['scale_barriers']           ?: null;
        $b_growth          = $d['growth_vision']            ?: null;
        $b_fund_use        = (string)($d['funding_use']              ?? '');
        $b_fund_act        = $d['funding_activities']       ?: null;
        $b_fund_out        = $d['funding_outcomes']         ?: null;
        $b_accel           = $d['accelerator_commitment']   ?: null;
        $b_expect          = $d['expectations']             ?: null;
        $b_status          = $import_status;
        $b_sub_by          = $submitted_by;
        $b_sub_at          = ($import_status !== 'Draft') ? $now : null;
        $b_now             = $now;

        $sql = "
            INSERT INTO applications (
                opportunity_id,
                startup_name, registration_date, ursb_registered, legal_status, business_stage,
                incubation_participated, incubation_programs, founding_story,
                problem_statement, affected_population, problem_evidence,
                solution_description, uniqueness, learning_outcomes_improvement,
                theory_of_change, pedagogy_approach, video_link,
                product_stage, website, demo_link, evaluation_evidence,
                team_members, team_size, founder_names, founder_gender, founder_age_range,
                founder_experience, commitment_level, advisors,
                contact_person, email, phone, physical_address,
                traction, metrics, partnerships, inclusion_approach,
                low_connectivity, safeguarding,
                current_revenue_detail, current_revenue, funding_raised, funding_sought,
                scale_plan_8000, scale_barriers, growth_vision,
                funding_use, funding_activities, funding_outcomes, accelerator_commitment,
                expectations,
                status, submitted_by, submitted_at, created_at, updated_at
            ) VALUES (
                ?,
                ?,?,?,?,?, ?,?,?,
                ?,?,?,
                ?,?,?, ?,?,?,
                ?,?,?,?,
                ?,?,?,?,?, ?,?,?,
                ?,?,?,?,
                ?,?,?,?, ?,?,
                ?,?,?,?, ?,?,?,
                ?,?,?,?,
                ?,
                ?,?,?,?,?
            )";

        $ins = $conn->prepare($sql);
        if (!$ins) {
            $errors[] = "Row $row_num: Prepare failed - " . $conn->error;
            $skipped++;
            continue;
        }

        $bound = $ins->bind_param(
            "issssssss" .
            "sss" .
            "ssssss" .
            "ssss" .
            "sissssss" .
            "ssss" .
            "ssssss" .
            "sddd" .
            "sss" .
            "ssss" .
            "s" .
            "sisss",
            $b_opp_id,
            $b_startup, $b_reg_date, $b_ursb, $b_legal, $b_stage,
            $b_incub_part, $b_incub_prog, $b_founding,
            $b_problem, $b_affected, $b_prob_ev,
            $b_solution, $b_unique, $b_lo_improve,
            $b_toc, $b_pedagogy, $b_video,
            $b_prod_stage, $b_website, $b_demo, $b_eval_ev,
            $b_team_members, $b_team_size, $b_founder_names, $b_founder_gender, $b_founder_age,
            $b_founder_exp, $b_commitment, $b_advisors,
            $b_contact, $b_email, $b_phone, $b_address,
            $b_traction, $b_metrics, $b_partnerships, $b_inclusion,
            $b_low_conn, $b_safeguard,
            $b_rev_detail, $b_cur_rev, $b_fund_raised, $b_fund_sought,
            $b_scale_plan, $b_scale_barriers, $b_growth,
            $b_fund_use, $b_fund_act, $b_fund_out, $b_accel,
            $b_expect,
            $b_status, $b_sub_by, $b_sub_at, $b_now, $b_now
        );

        if (!$bound) {
            $errors[] = "Row $row_num ({$b_startup}): bind_param failed - " . $ins->error;
            $skipped++;
            $ins->close();
            continue;
        }

        if ($ins->execute()) {
            $app_id = (int)$ins->insert_id;
            $inserted++;
            $conn->query("INSERT INTO application_status_history
                          (application_id, old_status, new_status, changed_by, comments)
                          VALUES ($app_id, NULL, '$import_status', $current_user, 'Imported via CSV')");
        } else {
            $errors[] = "Row $row_num ({$b_startup}): DB error - " . $ins->error;
            $skipped++;
        }
        $ins->close();
    }

    fclose($handle);
    $import_results = [
        'inserted'       => $inserted,
        'skipped'        => $skipped,
        'sample_skipped' => $sample_skipped,
        'errors'         => $errors,
        'total_rows'     => $row_num - $rows_scanned,   // data rows seen (excl. header rows)
        'delim_used'     => $delim === "\t" ? 'tab' : ($delim === ';' ? 'semicolon' : ($delim === '|' ? 'pipe' : 'comma')),
        'header_found'   => $best_matches >= 1,
        'key_matches'    => $best_matches,
    ];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<style>
/* CSV import: numbered steps, column legend, drag-and-drop zone, result details. */
.ci-alert { align-items: flex-start; }
.ci-alert > i { margin-top: 3px; }
.ci-alert h3 { margin: 0 0 8px; font-size: 16px; }
.ci-alert p { margin: 0 0 10px; }
.ci-error-list { max-height: 260px; overflow-y: auto; margin: 8px 0 0; padding: 0; list-style: none; font-size: 12px; }
.ci-error-list li { background: var(--surface-card); border: 1px solid var(--red-border); border-radius: var(--radius-sm); padding: 5px 8px; margin-bottom: 4px; color: var(--red-fg); }
.ci-diag-wrap .table { min-width: 0; background: var(--surface-card); }
.ci-hint { margin: 12px 0 0; font-size: 13px; }

.ci-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
.ci-step { background: var(--brand-50); border: 1px solid var(--brand-100); border-radius: var(--radius-md); padding: 18px; }
.ci-step-num { width: 34px; height: 34px; border-radius: 50%; background: var(--brand-500); color: var(--ink-0); font-weight: 700; font-size: 15px; display: flex; align-items: center; justify-content: center; margin-bottom: 10px; }
.ci-step h4 { margin: 0 0 6px; font-size: 14px; font-weight: 700; color: var(--ink-700); }
.ci-step p { margin: 0; font-size: 13px; color: var(--ink-400); }

.ci-intro { margin: 0 0 16px; color: var(--ink-400); font-size: 14px; }
.ci-details { margin-top: 20px; }
.ci-details summary { cursor: pointer; font-weight: 600; color: var(--brand-600); font-size: 14px; }
.ci-legend { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 6px; margin-top: 10px; }
.ci-col-item { background: var(--ink-50); border-radius: var(--radius-sm); padding: 7px 10px; font-size: 12px; display: flex; align-items: center; gap: 8px; }
.ci-col-item .ci-req { color: var(--brand-600); font-weight: 700; }
.ci-col-item .ci-opt { color: var(--ink-200); }
.ci-col-item .ci-col-label { color: var(--ink-300); }

.ci-upload-zone { border: 2px dashed var(--brand-400); border-radius: var(--radius-md); padding: 30px; text-align: center; cursor: pointer; transition: .2s; background: var(--brand-50); }
.ci-upload-zone:hover, .ci-upload-zone.drag-over { background: var(--brand-100); border-color: var(--brand-600); }
.ci-upload-zone > i { font-size: 40px; color: var(--brand-500); display: block; margin-bottom: 10px; }
.ci-upload-zone label { cursor: pointer; font-weight: 600; color: var(--brand-600); font-size: 15px; }
.ci-upload-zone input[type=file] { display: none; }
.ci-upload-zone small { display: block; color: var(--ink-300); margin-top: 6px; font-size: 12px; }
#file-chosen { margin-top: 10px; font-size: 13px; font-weight: 600; color: var(--green-fg); }
.ci-form-inline { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-top: 16px; }
.ci-form-inline .form-group { margin: 0; }
.ci-note { margin: 12px 0 0; font-size: 12px; color: var(--ink-300); }
</style>

<div class="page-stack">

<div class="page-hero">
    <div>
        <h1><i class="fas fa-file-csv" aria-hidden="true"></i> CSV Import - Applications</h1>
        <p><?php echo htmlspecialchars($opportunity['opportunity_title']); ?></p>
    </div>
    <div class="hero-actions">
        <a href="manage-applications?opportunity_id=<?php echo $opportunity_id; ?>" class="btn">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Manage Applications
        </a>
    </div>
</div>

<?php if ($import_results !== null): ?>
    <?php if ($import_results['inserted'] > 0): ?>
    <div class="alert alert-success ci-alert">
        <i class="fas fa-check-circle" aria-hidden="true"></i>
        <div>
            <h3>Import Complete</h3>
            <p>
                <strong><?php echo $import_results['inserted']; ?></strong> application(s) imported.
                <?php if ($import_results['skipped'] > 0): ?>
                    &nbsp;|&nbsp; <strong><?php echo $import_results['skipped']; ?></strong> skipped.
                <?php endif; ?>
            </p>
            <a href="manage-applications?opportunity_id=<?php echo $opportunity_id; ?>"
               class="btn btn-primary btn-sm">
                <i class="fas fa-eye" aria-hidden="true"></i> View Applications
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($import_results['errors'])): ?>
    <div class="alert <?php echo $import_results['inserted'] > 0 ? 'alert-warning' : 'alert-danger'; ?> ci-alert">
        <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
        <div>
            <h3><?php echo count($import_results['errors']); ?> row(s) had errors</h3>
            <ul class="ci-error-list">
                <?php foreach ($import_results['errors'] as $err): ?>
                    <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($import_results['inserted'] === 0 && empty($import_results['errors'])): ?>
    <div class="alert alert-danger ci-alert">
        <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
        <div>
        <h3>Nothing Was Imported</h3>

        <?php
        $r             = $import_results;
        $only_samples  = $r['sample_skipped'] > 0 && $r['sample_skipped'] === $r['total_rows'];
        $no_rows       = $r['total_rows'] === 0;
        $header_bad    = !$r['header_found'];
        ?>

        <?php if ($no_rows): ?>
        <p><strong>The file appears to be empty or contains only header rows.</strong>
        Make sure you have added at least one row of your own data below the two header rows before uploading.</p>

        <?php elseif ($only_samples): ?>
        <p><strong>The file only contained the <?php echo $r['sample_skipped']; ?> built-in sample rows - no real applications were found.</strong>
        Add your own application rows below the sample rows (or delete the sample rows first), then re-upload.</p>

        <?php elseif ($header_bad): ?>
        <p><strong>The column header row could not be found.</strong>
        The importer looks for a row containing the machine-key names (e.g. <code>startup_name</code>, <code>email</code>, <code>business_stage</code>).
        Please re-download the template and do not delete or edit row 2.</p>

        <?php else: ?>
        <p><strong>All <?php echo $r['total_rows']; ?> data row(s) were skipped.</strong>
        This usually means they were blank or matched a built-in sample email address.</p>
        <?php endif; ?>

        <div class="table-responsive ci-diag-wrap">
            <table class="table">
                <tbody>
                    <tr><td>Delimiter detected</td><td><strong><?php echo htmlspecialchars($r['delim_used']); ?></strong></td></tr>
                    <tr><td>Header row found</td><td><strong><?php echo $r['header_found'] ? 'Yes ('.$r['key_matches'].' column keys matched)' : 'No'; ?></strong></td></tr>
                    <tr><td>Total data rows seen</td><td><strong><?php echo $r['total_rows']; ?></strong></td></tr>
                    <tr><td>Sample rows skipped</td><td><strong><?php echo $r['sample_skipped']; ?></strong></td></tr>
                    <tr><td>Other rows skipped</td><td><strong><?php echo $r['skipped'] - $r['sample_skipped']; ?></strong></td></tr>
                </tbody>
            </table>
        </div>

        <?php if (!$only_samples && !$no_rows && !$header_bad): ?>
        <p class="ci-hint">
            If you believe your file is correct, check that:<br>
            &bull; You are not uploading the template with only sample rows filled in<br>
            &bull; Your email addresses are not the same as the built-in sample emails<br>
            &bull; Your file is saved as <strong>.csv</strong> (not .xlsx or .xls)
        </p>
        <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-list-ol" aria-hidden="true"></i> How It Works</h3></div>
    <div class="card-body">
        <div class="ci-steps">
            <div class="ci-step">
                <div class="ci-step-num">1</div>
                <h4>Download Template</h4>
                <p>Get the pre-formatted CSV with all column headers and one example row.</p>
            </div>
            <div class="ci-step">
                <div class="ci-step-num">2</div>
                <h4>Fill In Data</h4>
                <p>Add your applications below the two header rows. The 5 sample rows are skipped automatically - you can leave them in as a reference. Fields marked * are required.</p>
            </div>
            <div class="ci-step">
                <div class="ci-step-num">3</div>
                <h4>Upload &amp; Import</h4>
                <p>Select your saved CSV and choose a status. Duplicate emails are automatically skipped.</p>
            </div>
            <div class="ci-step">
                <div class="ci-step-num">4</div>
                <h4>Review Results</h4>
                <p>A summary shows how many rows were imported and details of any errors.</p>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-download" aria-hidden="true"></i> Download Template</h3></div>
    <div class="card-body">
        <div class="alert alert-warning">
            <div>
                <strong>Excel users:</strong> The template is optimised to prevent Excel from corrupting your data.
                Phone numbers and dates are pre-formatted as text. If you still see dates change to
                <em>dd/mm/yyyy</em> or phone numbers change to scientific notation after editing,
                the importer will correct them automatically on upload.
            </div>
        </div>

        <p class="ci-intro">
            Contains <strong><?php echo count($CSV_COLUMNS); ?> columns</strong> covering all
            application fields, with a human-readable label row, a machine-key row, and
            <strong>5 realistic sample rows</strong> showing how to fill every field correctly.
            Sample rows are skipped automatically on import - no need to delete them.
            The importer auto-detects the key row and handles files re-saved from Excel or LibreOffice.
        </p>
        <a href="application-csv-import?opportunity_id=<?php echo $opportunity_id; ?>&action=download_template"
           class="btn btn-primary">
            <i class="fas fa-file-csv" aria-hidden="true"></i> Download CSV Template
        </a>

        <details class="ci-details">
            <summary>
                <i class="fas fa-table" aria-hidden="true"></i> View all column definitions
            </summary>
            <div class="ci-legend">
                <?php foreach ($CSV_COLUMNS as $key => $col): ?>
                <div class="ci-col-item">
                    <?php if ($col['required']): ?>
                        <span class="ci-req" title="Required">*</span>
                    <?php else: ?>
                        <span class="ci-opt" title="Optional">o</span>
                    <?php endif; ?>
                    <div>
                        <strong><?php echo htmlspecialchars($key); ?></strong><br>
                        <span class="ci-col-label"><?php echo htmlspecialchars($col['label']); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-upload" aria-hidden="true"></i> Upload &amp; Import</h3></div>
    <div class="card-body">
        <form method="POST" action="" enctype="multipart/form-data" id="importForm">
            <input type="hidden" name="csrf_token"
                   value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="ci-upload-zone" id="dropZone">
                <i class="fas fa-cloud-upload-alt" aria-hidden="true"></i>
                <label for="csv_file">Click to browse or drag &amp; drop your CSV</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required
                       onchange="showFileName(this)">
                <small>Accepted: .csv only</small>
                <div id="file-chosen"></div>
            </div>

            <div class="ci-form-inline">
                <div class="form-group">
                    <label for="import_status">Import as status</label>
                    <select name="import_status" id="import_status" class="form-control">
                        <option value="Submitted">Submitted</option>
                        <option value="Under Review">Under Review</option>
                        <option value="Draft">Draft</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" id="importBtn">
                    <i class="fas fa-file-import" aria-hidden="true"></i> Import Applications
                </button>
            </div>

            <p class="ci-note">
                <i class="fas fa-info-circle" aria-hidden="true"></i>
                Rows where the email already has a non-draft application for this opportunity
                are skipped. New email addresses get an Applicant account created automatically.
            </p>
        </form>
    </div>
</div>

</div><!-- /.page-stack -->

<?php include 'includes/footer.php'; ?>

<script>
function showFileName(input) {
    const el = document.getElementById('file-chosen');
    if (input.files && input.files[0]) {
        el.textContent = '\u2714 ' + input.files[0].name;
    }
}

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('csv_file');

['dragenter','dragover'].forEach(ev =>
    dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('drag-over'); })
);
['dragleave','drop'].forEach(ev =>
    dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.remove('drag-over'); })
);
dropZone.addEventListener('drop', e => {
    const f = e.dataTransfer.files[0];
    if (f) {
        const dt = new DataTransfer();
        dt.items.add(f);
        fileInput.files = dt.files;
        showFileName(fileInput);
    }
});

document.getElementById('importForm').addEventListener('submit', function () {
    const btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
});
</script>