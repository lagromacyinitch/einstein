<?php
/**
 * Database-backed copy used by the public homepage.
 *
 * Prices and center contact details intentionally remain in their existing
 * settings/rates stores. This model owns the editable text and list content
 * rendered by public/main.html.
 */
final class HomepageContent
{
    public const TABLE = 'homepage_content';

    private static function field(
        string $key,
        string $section,
        string $label,
        $value,
        string $type = 'text',
        int $sort = 0
    ): array {
        return compact('key', 'section', 'label', 'value', 'type', 'sort');
    }

    /**
     * The seed values mirror the copy currently shown on the homepage and
     * the supplied homepage references. Existing database values are never
     * overwritten when these defaults are seeded.
     */
    public static function definitions(): array
    {
        $f = static fn($key, $section, $label, $value, $type = 'text', $sort = 0)
            => self::field($key, $section, $label, $value, $type, $sort);

        return [
            // Branding and navigation
            $f('brand.prefix', 'Hero & Branding', 'Brand prefix', 'EINSTEIN-'),
            $f('brand.name', 'Hero & Branding', 'Brand name', 'Center For Modern Education'),
            $f('brand.logo_alt', 'Hero & Branding', 'Logo alternative text', 'Einstein Center For Modern Education'),
            $f('nav.programs', 'Hero & Branding', 'Navigation: Programs', 'Programs'),
            $f('nav.tutorial', 'Hero & Branding', 'Navigation: Tutorial', 'Tutorial'),
            $f('nav.workshop', 'Hero & Branding', 'Navigation: Workshop', 'Workshop'),
            $f('nav.playschool', 'Hero & Branding', 'Navigation: Playschool', 'Playschool'),
            $f('nav.childcare', 'Hero & Branding', 'Navigation: Child Care', 'Child Care'),
            $f('nav.madstudio', 'Hero & Branding', 'Navigation: M.A.D. Studio', 'M.A.D. Studio'),
            $f('nav.summerblast', 'Hero & Branding', 'Navigation: Summer Blast', 'Summer Blast'),
            $f('nav.membership', 'Hero & Branding', 'Navigation: Membership', 'Membership'),
            $f('auth.login', 'Hero & Branding', 'Login label', 'Login'),
            $f('hiring.banner', 'Hero & Branding', 'Hiring announcement', '📢 We Are Hiring Staff/Tutors! Join our growing family. Contact us today.'),
            $f('hero.cursive', 'Hero & Branding', 'Hero headline', 'Tutorial . Workshop . Child Care'),
            $f('hero.tagline', 'Hero & Branding', 'Business tagline', "The Ultimate Learning Center of Choice for Parents who are Passionate,\nHighly Committed and Desperately in Pursuit of World Class Education for their Child", 'textarea'),

            // Programs overview
            $f('programs.label', 'Programs & Services', 'Section label', 'What We Offer'),
            $f('programs.title', 'Programs & Services', 'Section heading', 'Programs & Services'),
            $f('programs.description', 'Programs & Services', 'Section description', "From toddler care to academic tutorials and weekend workshops, we have a program for every child's stage and interest.", 'textarea'),
            $f('programs.academic.title', 'Programs & Services', 'Academic Tutorial title', 'Academic Tutorial'),
            $f('programs.academic.subtitle', 'Programs & Services', 'Academic Tutorial subtitle', 'For school-age children'),
            $f('programs.academic.tag', 'Programs & Services', 'Academic Tutorial tag', 'Tutoring'),
            $f('programs.academic.details', 'Programs & Services', 'Academic Tutorial details', ['One-on-one expert tutors', 'All subjects & modules covered', 'Flexible scheduling options', 'Online booking available'], 'list'),
            $f('programs.workshop.title', 'Programs & Services', 'Weekend Workshop title', 'Weekend Workshop'),
            $f('programs.workshop.subtitle', 'Programs & Services', 'Weekend Workshop subtitle', 'Saturdays • Aug to Jan'),
            $f('programs.workshop.tag', 'Programs & Services', 'Weekend Workshop tag', 'Enrichment'),
            $f('programs.workshop.details', 'Programs & Services', 'Weekend Workshop details', ['Music, arts, and sports', 'Group & 1-on-1 options', 'Grand Recital at Island City Mall', 'Pool fee not yet included for swimming'], 'list'),
            $f('programs.playschool.title', 'Programs & Services', 'PlaySchool title', 'PlaySchool'),
            $f('programs.playschool.subtitle', 'Programs & Services', 'PlaySchool subtitle', 'Ages 2.6 to 4.5 years old'),
            $f('programs.playschool.tag', 'Programs & Services', 'PlaySchool tag', 'Early Education'),
            $f('programs.playschool.details', 'Programs & Services', 'PlaySchool details', ['Caterpillar & Butterfly classes', 'Arts, literacy, numeracy', 'Morning & afternoon sessions', 'Mon • Fri schedule'], 'list'),
            $f('programs.childcare.title', 'Programs & Services', 'Child Care title', 'Child Care Program'),
            $f('programs.childcare.subtitle', 'Programs & Services', 'Child Care subtitle', 'Ages 1.6 to 4.5 years old'),
            $f('programs.childcare.tag', 'Programs & Services', 'Child Care tag', 'Daycare'),
            $f('programs.childcare.details', 'Programs & Services', 'Child Care details', ['Up to 12 hrs nanny care (7am • 7pm)', 'Breakfast & lunch included', 'Life skills & play-based learning', 'Everyday except Sundays'], 'list'),
            $f('programs.madstudio.title', 'Programs & Services', 'M.A.D. Studio title', 'M.A.D. Studio'),
            $f('programs.madstudio.subtitle', 'Programs & Services', 'M.A.D. Studio subtitle', 'Fitness & After-school Programs'),
            $f('programs.madstudio.tag', 'Programs & Services', 'M.A.D. Studio tag', 'Our Services'),
            $f('programs.madstudio.details', 'Programs & Services', 'M.A.D. Studio details', ['Fitness Training • Hiphop Aerobics, Kickboxing', 'After School Program • Ballet, Taekwondo, Gymnastics, Pop Dancing', 'Studio Rental'], 'list'),
            $f('programs.summerblast.title', 'Programs & Services', 'Summer Blast title', 'Summer Blast'),
            $f('programs.summerblast.subtitle', 'Programs & Services', 'Summer Blast subtitle', 'Year 14 • April to May'),
            $f('programs.summerblast.tag', 'Programs & Services', 'Summer Blast tag', 'Summer Program'),
            $f('programs.summerblast.details', 'Programs & Services', 'Summer Blast details', ['Academics, Music, Arts & Sports', 'Choose 1 or 2 courses plus free academics', 'Grand Recital at Island City Mall', 'Reserve your slot for only ₱900'], 'list'),

            // Academic Tutorial
            $f('tutorial.label', 'Academic Tutorial', 'Section label', 'Academic Tutorial'),
            $f('tutorial.title', 'Academic Tutorial', 'Section heading', 'Tutoring Packages'),
            $f('tutorial.description', 'Academic Tutorial', 'Section description', "Choose the plan that fits your child's needs. All packages cover all subjects and modules with guaranteed competent tutors.", 'textarea'),
            $f('tutorial.regular.label', 'Academic Tutorial', 'Regular Package label', 'Regular Package'),
            $f('tutorial.regular.period', 'Academic Tutorial', 'Regular Package period', 'One-time payment'),
            $f('tutorial.regular.features', 'Academic Tutorial', 'Regular Package details', ['One Tutor for One Child', '15 sessions', '1 hour per session', 'All subjects & modules'], 'list'),
            $f('tutorial.double.label', 'Academic Tutorial', 'Double Package label', 'Double Package'),
            $f('tutorial.double.period', 'Academic Tutorial', 'Double Package period', 'One-time payment'),
            $f('tutorial.double.features', 'Academic Tutorial', 'Double Package details', ['One Tutor for 1 or 2 Kids', '15 sessions', '2 hours per session', 'All subjects & modules'], 'list'),
            $f('tutorial.daily.label', 'Academic Tutorial', 'Daily Package label', 'Daily Package'),
            $f('tutorial.daily.period', 'Academic Tutorial', 'Daily Package period', 'Per month'),
            $f('tutorial.daily.features', 'Academic Tutorial', 'Daily Package details', ['One Tutor for One Child', 'Everyday session for 1 month', '1 hour per day', 'All subjects & modules'], 'list'),
            $f('tutorial.daily_double.label', 'Academic Tutorial', 'Daily Double Package label', 'Daily Double Package'),
            $f('tutorial.daily_double.period', 'Academic Tutorial', 'Daily Double Package period', 'Per month'),
            $f('tutorial.daily_double.features', 'Academic Tutorial', 'Daily Double Package details', ['One Tutor for 1 or 2 Kids', 'Everyday session for 1 month', '2 hours per session', 'All subjects & modules'], 'list'),
            $f('tutorial.note.reservation.title', 'Academic Tutorial', 'Reservation note title', 'Reservation Basis Only'),
            $f('tutorial.note.reservation.description', 'Academic Tutorial', 'Reservation note description', 'Slots are limited per timeslot'),
            $f('tutorial.note.limited.title', 'Academic Tutorial', 'Limited Students note title', 'Limited Students'),
            $f('tutorial.note.limited.description', 'Academic Tutorial', 'Limited Students note description', 'Per timeslot for quality sessions'),
            $f('tutorial.note.vip.title', 'Academic Tutorial', 'VIP Card Discounts note title', 'VIP Card Discounts'),
            $f('tutorial.note.vip.description', 'Academic Tutorial', 'VIP Card Discounts note description', 'Available for VIP card holders'),
            $f('tutorial.note.expires.title', 'Academic Tutorial', 'Expires note title', 'Expires in 30 Days'),
            $f('tutorial.note.expires.description', 'Academic Tutorial', 'Expires note description', 'Package validity from start date'),

            // Weekend Workshop
            $f('workshop.label', 'Weekend Workshop', 'Section label', 'Weekend Workshop'),
            $f('workshop.title', 'Weekend Workshop', 'Section heading', 'Saturdays Only\nAugust to January', 'textarea'),
            $f('workshop.description', 'Weekend Workshop', 'Section description', "Enrich your child's weekends with music, arts, and sports led by dedicated instructors. Limited slots available.", 'textarea'),
            $f('workshop.activities_label', 'Weekend Workshop', 'Activities label', 'Available Activities'),
            $f('workshop.activities', 'Weekend Workshop', 'Available activities', ['Drum', 'Guitar', 'Piano', 'Voice', 'Violin', 'Basketball', 'Gymnastics', 'Taekwondo', 'Ballet', 'Drawing & Painting', 'Swimming'], 'list'),
            $f('workshop.group.label', 'Weekend Workshop', 'Group subscription label', 'Group Subscription'),
            $f('workshop.group.period', 'Weekend Workshop', 'Group subscription period', 'Up to 10 students per class'),
            $f('workshop.group.includes_label', 'Weekend Workshop', 'Group includes label', 'Group Includes'),
            $f('workshop.group.includes', 'Weekend Workshop', 'Group inclusions', ['Limited to 10 students per class', 'One hour per session', 'Must bring your own instrument (except drums)', 'Grand Recital at Island City Mall'], 'list'),
            $f('workshop.one_on_one_label', 'Weekend Workshop', 'One-on-one subscriptions label', 'One-on-One Subscriptions'),
            $f('workshop.center.title', 'Weekend Workshop', 'Center Based title', 'Center Based'),
            $f('workshop.center.features', 'Weekend Workshop', 'Center Based details', ['1 instructor to 1 student', '10 sessions, 1 hr each', 'Bring your own instrument (except drums)', 'Pool fee not yet included'], 'list'),
            $f('workshop.home.title', 'Weekend Workshop', 'Home Based title', 'Home Based'),
            $f('workshop.home.price_suffix', 'Weekend Workshop', 'Home Based price suffix', '+transpo'),
            $f('workshop.home.features', 'Weekend Workshop', 'Home Based details', ['1 instructor to 1 student', '10 sessions, 1 hr each', 'Must have your own instrument', 'Negotiate transpo with coach'], 'list'),

            // PlaySchool
            $f('playschool.label', 'PlaySchool', 'Section label', 'Playschool'),
            $f('playschool.title', 'PlaySchool', 'Section heading', 'Early Childhood Education'),
            $f('playschool.description', 'PlaySchool', 'Section description', "Slots are limited and few are already reserved. Join now to secure your child's place.", 'textarea'),
            $f('playschool.caterpillar.title', 'PlaySchool', 'Caterpillar title', 'Caterpillar Class'),
            $f('playschool.caterpillar.age', 'PlaySchool', 'Caterpillar age', 'For kids 2.6 to 3.5 years old'),
            $f('playschool.caterpillar.learn_label', 'PlaySchool', 'Caterpillar learning label', 'What to Learn'),
            $f('playschool.caterpillar.learn', 'PlaySchool', 'Caterpillar learning details', ['Arts & Crafts', 'Potty Training', 'Shapes & Colors', 'Counting Numbers', 'Social Skills', 'Rhymes and Songs', 'Outdoor Activities', 'Educational Tours'], 'list'),
            $f('playschool.caterpillar.schedule_heading', 'PlaySchool', 'Caterpillar schedule heading', 'Monday to Friday'),
            $f('playschool.caterpillar.morning', 'PlaySchool', 'Caterpillar morning schedule', 'Morning Classes: 9:00 • 11:30'),
            $f('playschool.caterpillar.afternoon', 'PlaySchool', 'Caterpillar afternoon schedule', 'Afternoon Classes: 1:00 • 3:30'),
            $f('playschool.butterfly.title', 'PlaySchool', 'Butterfly title', 'Butterfly Class'),
            $f('playschool.butterfly.age', 'PlaySchool', 'Butterfly age', 'For kids 3.6 to 4.5 years old'),
            $f('playschool.butterfly.learn_label', 'PlaySchool', 'Butterfly learning label', 'What to Learn'),
            $f('playschool.butterfly.learn', 'PlaySchool', 'Butterfly learning details', ['Drawing & Painting', 'Read & Write', 'Arithmetic', 'Sports & Physical Play', 'House Chores', 'Music & Dance', 'Outdoor Activities', 'Educational Tours'], 'list'),
            $f('playschool.butterfly.schedule_heading', 'PlaySchool', 'Butterfly schedule heading', 'Monday to Friday'),
            $f('playschool.butterfly.morning', 'PlaySchool', 'Butterfly morning schedule', 'Morning Classes: 9:00 • 11:30'),
            $f('playschool.butterfly.afternoon', 'PlaySchool', 'Butterfly afternoon schedule', 'Afternoon Classes: 1:00 • 3:30'),

            // Child care
            $f('childcare.label', 'Child Care', 'Section label', 'Child Care Program'),
            $f('childcare.title', 'Child Care', 'Section heading', 'Full-Day Care\nfor Little Ones', 'textarea'),
            $f('childcare.description', 'Child Care', 'Section description', 'For kids 1.6 to 4.5 years old. Slots are limited and few are already taken.'),
            $f('childcare.inclusions_label', 'Child Care', 'Inclusions label', 'Inclusions'),
            $f('childcare.inclusions', 'Child Care', 'Inclusions', ['Up to 12 hrs of Nanny Care (7am • 7pm)', 'Everyday except Sundays', 'Breakfast and lunch included', 'Life skills training', 'Access to toy room and nap room', 'Video call anytime of the day', 'With story time and TV time', 'Student kit, utensils and toiletries'], 'list'),
            $f('childcare.admission_label', 'Child Care', 'Admission note label', 'What to Bring During Admission'),
            $f('childcare.admission_items', 'Child Care', 'Admission items', 'Feeding Bottles, Formula Milk, Diapers, Extra Clothes, Favorite Toy'),
            $f('childcare.admission_note', 'Child Care', 'Admission note', 'Food for dinner is optional and can also be pre-ordered for only ₱45 per meal.', 'textarea'),
            $f('childcare.rates_label', 'Child Care', 'Package rates label', 'Package Rates'),
            $f('childcare.toilet_trained_label', 'Child Care', 'Toilet trained section label', 'For Toilet Trained Kids'),
            $f('childcare.non_toilet_trained_label', 'Child Care', 'Non-toilet trained section label', 'For Non-Toilet Trained Kids'),
            $f('childcare.monthly_label', 'Child Care', 'Monthly column label', 'Monthly'),
            $f('childcare.weekly_label', 'Child Care', 'Weekly column label', 'Weekly'),
            $f('childcare.daily_label', 'Child Care', 'Daily column label', 'Daily'),
            $f('childcare.nonmember_label', 'Child Care', 'Non-members row label', 'Non-members'),
            $f('childcare.vip_label', 'Child Care', 'VIP Members row label', 'VIP Members'),

            // M.A.D. Studio
            $f('mad.label', 'M.A.D. Studio', 'Section label', 'M.A.D. Studio'),
            $f('mad.title', 'M.A.D. Studio', 'Section heading', 'Fitness and\nAfter School Programs', 'textarea'),
            $f('mad.services_label', 'M.A.D. Studio', 'Services heading', 'Our Services'),
            $f('mad.fitness.heading', 'M.A.D. Studio', 'Fitness Training heading', 'Fitness Training'),
            $f('mad.fitness.items', 'M.A.D. Studio', 'Fitness Training services', ['Hiphop Aerobics', 'Kickboxing'], 'list'),
            $f('mad.after_school.heading', 'M.A.D. Studio', 'After School Program heading', 'After School Program'),
            $f('mad.after_school.items', 'M.A.D. Studio', 'After School Program services', ['Ballet Class', 'Taekwondo', 'Gymnastics', 'Pop Dancing'], 'list'),
            $f('mad.rental.heading', 'M.A.D. Studio', 'Studio Rental heading', 'Studio Rental'),
            $f('mad.rental.items', 'M.A.D. Studio', 'Studio Rental services', ['Available for rehearsals & events', '₱450 per hour'], 'list'),
            $f('mad.rates_label', 'M.A.D. Studio', 'Rates and schedule heading', 'Rates & Schedule'),
            $f('mad.rates.fitness_heading', 'M.A.D. Studio', 'Fitness rates heading', 'Fitness Training'),
            $f('mad.rates.after_school_heading', 'M.A.D. Studio', 'After School rates heading', 'After School Program'),
            $f('mad.rates.rental_heading', 'M.A.D. Studio', 'Studio Rental rates heading', 'Studio Rental'),
            $f('mad.rates.hiphop.name', 'M.A.D. Studio', 'Hiphop Aerobics rate name', 'Hiphop Aerobics'),
            $f('mad.rates.hiphop.schedule', 'M.A.D. Studio', 'Hiphop Aerobics schedule', 'MWF • 6:45-7:45pm'),
            $f('mad.rates.kickboxing.name', 'M.A.D. Studio', 'Kickboxing rate name', 'Kickboxing'),
            $f('mad.rates.kickboxing.schedule', 'M.A.D. Studio', 'Kickboxing schedule', 'TTHS • 6:45-7:45pm'),
            $f('mad.rates.cross_training.name', 'M.A.D. Studio', 'Cross Training rate name', 'Cross Training (both classes)'),
            $f('mad.rates.cross_training.schedule', 'M.A.D. Studio', 'Cross Training schedule', 'Both schedules'),
            $f('mad.rates.gymnastics.name', 'M.A.D. Studio', 'Gymnastics rate name', 'Gymnastics'),
            $f('mad.rates.gymnastics.schedule', 'M.A.D. Studio', 'Gymnastics schedule', 'Sat • 8:30-10:30am'),
            $f('mad.rates.ballet.name', 'M.A.D. Studio', 'Ballet Class rate name', 'Ballet Class'),
            $f('mad.rates.ballet.schedule', 'M.A.D. Studio', 'Ballet Class schedule', 'Sat • 10:30am-12:30pm'),
            $f('mad.rates.taekwondo.name', 'M.A.D. Studio', 'Taekwondo rate name', 'Taekwondo'),
            $f('mad.rates.taekwondo.schedule', 'M.A.D. Studio', 'Taekwondo schedule', 'Sat 12:30-2:30 • TTHS 5:30-6:30'),
            $f('mad.rates.pop_dancing.name', 'M.A.D. Studio', 'Pop Dancing rate name', 'Pop Dancing'),
            $f('mad.rates.pop_dancing.schedule', 'M.A.D. Studio', 'Pop Dancing schedule', 'MWF • 5:30-6:30pm'),
            $f('mad.rates.studio_rental.name', 'M.A.D. Studio', 'Studio Rental rate name', 'Studio Rental'),
            $f('mad.rates.studio_rental.unit', 'M.A.D. Studio', 'Studio Rental rate unit', '/ hour'),

            // Summer Blast and restored Important Dates
            $f('summer.label', 'Summer Blast', 'Section label', 'Summer Program'),
            $f('summer.title', 'Summer Blast', 'Section heading', 'Summer Blast'),
            $f('summer.description', 'Summer Blast', 'Description under heading', 'Learning starts April 15, 2026. Grand Recital at Island City Mall.Slots are limited to only 15 students per class.', 'textarea'),
            $f('summer.reserve_label', 'Summer Blast', 'Reservation banner label', 'Reserve Your Slot'),
            $f('summer.reserve_period', 'Summer Blast', 'Reservation banner description', 'Includes free t-shirt, photo & video coverage, recital fee, certificates & more'),
            $f('summer.registration_period', 'Summer Blast', 'Registration fee period', 'Registration fee'),
            $f('summer.tuition_label', 'Summer Blast', 'Tuition packages label', 'Tuition Fee Packages'),
            $f('summer.single.label', 'Summer Blast', 'Single Course label', 'Single Course'),
            $f('summer.single.period', 'Summer Blast', 'Single Course period', 'One-time payment'),
            $f('summer.single.features', 'Summer Blast', 'Single Course details', ['Choose ONE course', 'Plus 1 Academics subject FREE'], 'list'),
            $f('summer.two.label', 'Summer Blast', 'Two Courses label', 'Two Courses'),
            $f('summer.two.period', 'Summer Blast', 'Two Courses period', 'One-time payment'),
            $f('summer.two.features', 'Summer Blast', 'Two Courses details', ['Choose TWO courses', 'Plus 1 Academics subject FREE'], 'list'),
            $f('summer.special.label', 'Summer Blast', 'Special Course label', 'Special Course'),
            $f('summer.special.period', 'Summer Blast', 'Special Course period', 'One-time payment'),
            $f('summer.special.features', 'Summer Blast', 'Special Course details', ['Choose ONE Special Course', 'Plus 1 Any Course FREE'], 'list'),
            $f('summer.course_offerings_label', 'Summer Blast', 'Course offerings label', 'Course Offerings'),
            $f('summer.offering.academics.title', 'Summer Blast', 'Academics offering title', 'Academics'),
            $f('summer.offering.academics.items', 'Summer Blast', 'Academics offering details', ['Basic Read & Write', 'Reading w/ Comprehension', 'Math for Elementary', 'Math for High School', 'Public Speaking / Hosting', 'Wikang Tagalog / Filipino'], 'list'),
            $f('summer.offering.music.title', 'Summer Blast', 'Music offering title', 'Music'),
            $f('summer.offering.music.items', 'Summer Blast', 'Music offering details', ['Drum Lessons', 'Guitar Lessons', 'Piano Lessons', 'Violin Lessons', 'Voice Coaching'], 'list'),
            $f('summer.offering.arts.title', 'Summer Blast', 'Arts & Dance offering title', 'Arts & Dance'),
            $f('summer.offering.arts.items', 'Summer Blast', 'Arts & Dance offering details', ['Ballet Lessons', 'Drawing & Painting', 'Pop Dancing & Afro Dance', 'Gymnastics', 'Modeling Class', 'Photography'], 'list'),
            $f('summer.offering.sports.title', 'Summer Blast', 'Sports offering title', 'Sports'),
            $f('summer.offering.sports.items', 'Summer Blast', 'Sports offering details', ['Basketball Clinic', 'Chess Clinic'], 'list'),
            $f('summer.offering.special.title', 'Summer Blast', 'Special Courses offering title', 'Special Courses'),
            $f('summer.offering.special.items', 'Summer Blast', 'Special Courses offering details', ['Baking Class', 'Swimming Lessons', 'Taekwondo Class'], 'list'),
            $f('summer.important_dates.title', 'Summer Blast', 'Important Dates heading', 'Important Dates'),
            $f('summer.important_dates.classes_start_label', 'Summer Blast', 'Classes start label', 'Classes start:'),
            $f('summer.important_dates.classes_start_value', 'Summer Blast', 'Classes start date', 'April 15'),
            $f('summer.important_dates.no_classes_label', 'Summer Blast', 'No classes label', 'No classes:'),
            $f('summer.important_dates.no_classes_value', 'Summer Blast', 'No classes dates', 'May 1–2'),
            $f('summer.important_dates.rehearsal_label', 'Summer Blast', 'Rehearsal label', 'Rehearsal:'),
            $f('summer.important_dates.rehearsal_value', 'Summer Blast', 'Rehearsal date', 'May 22'),
            $f('summer.important_dates.recital_label', 'Summer Blast', 'Grand Recital label', 'Grand Recital:'),
            $f('summer.important_dates.recital_value', 'Summer Blast', 'Grand Recital date', 'May 23'),

            // Membership, facilities and footer
            $f('membership.label', 'Membership', 'Section label', 'Get In Touch'),
            $f('membership.title', 'Membership', 'Section heading', 'Find Us & Join'),
            $f('membership.card.kicker', 'Membership', 'Membership card kicker', 'Be Rewarded'),
            $f('membership.card.title', 'Membership', 'Membership card title', 'Join our membership programme!'),
            $f('membership.card.benefits_title', 'Membership', 'Membership benefits heading', 'Membership Benefits'),
            $f('membership.card.benefits_description', 'Membership', 'Membership benefits description', 'Exclusive access to Tutorial, Workshop & Child Care Services'),
            $f('membership.card.vip_title', 'Membership', 'VIP club heading', 'VIP Club Membership'),
            $f('membership.card.discount', 'Membership', 'VIP discount text', 'Get up to 15% DISCOUNT'),
            $f('membership.card.duration', 'Membership', 'VIP duration text', 'on regular services for two years'),
            $f('membership.contact.title', 'Membership', 'Contact information heading', 'Contact Information'),
            $f('membership.contact.phone_label', 'Membership', 'Phone label', 'Phone / SMS'),
            $f('membership.contact.address_label', 'Membership', 'Address label', 'Address'),
            $f('membership.contact.facebook_label', 'Membership', 'Facebook label', 'Facebook'),
            $f('membership.contact.hours_label', 'Membership', 'Operating hours label', 'Operating Hours'),
            $f('membership.contact.hours_value', 'Membership', 'Operating hours value', 'Monday to Saturday • 7:00 AM • 7:00 PM'),
            $f('facilities.label', 'Facilities', 'Section label', 'Our Facilities'),
            $f('facilities.title', 'Facilities', 'Section heading', 'Center Facilities'),
            $f('facilities.description', 'Facilities', 'Section description', 'Everything your child needs in one safe, nurturing environment.'),
            $f('facilities.items', 'Facilities', 'Facilities list', ['Fully Air-Conditioned Rooms', '24/7 CCTV & Security', 'Mini Library', 'Canteen', 'Outdoor Play & Tours', 'Toy Room', 'Music Room', 'Personal Locker'], 'list'),
            $f('footer.since', 'Footer', 'Since text', 'Since 2012'),
            $f('footer.years', 'Footer', 'Years text', 'Years of trusted service'),
            $f('footer.business_name', 'Footer', 'Business name', 'Einstein Child Care & Learning Center'),
            $f('footer.copyright', 'Footer', 'Copyright text', '•2018 by Einstein - Center for Modern Education - ECME.'),
        ];
    }

    public static function ensureTable(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            content_key VARCHAR(191) NOT NULL UNIQUE,
            section_name VARCHAR(120) NOT NULL,
            field_label VARCHAR(255) NOT NULL,
            content_type VARCHAR(20) NOT NULL DEFAULT 'text',
            content_value LONGTEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_homepage_section (section_name, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function encodeValue($value, string $type): string
    {
        if ($type === 'list') {
            if (!is_array($value)) {
                $value = preg_split('/\r\n|\r|\n/', (string)$value);
            }
            $value = array_values(array_filter(array_map(static fn($item) => trim((string)$item), $value), static fn($item) => $item !== ''));
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return trim((string)$value);
    }

    private static function decodeValue(string $value, string $type)
    {
        if ($type === 'list') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? array_values($decoded) : [];
        }

        return $value;
    }

    public static function seed(PDO $db): void
    {
        self::ensureTable($db);
        $stmt = $db->prepare("INSERT INTO " . self::TABLE . "
            (content_key, section_name, field_label, content_type, content_value, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                section_name = VALUES(section_name),
                field_label = VALUES(field_label),
                content_type = VALUES(content_type),
                sort_order = VALUES(sort_order)");

        foreach (self::definitions() as $definition) {
            $stmt->execute([
                $definition['key'],
                $definition['section'],
                $definition['label'],
                $definition['type'],
                self::encodeValue($definition['value'], $definition['type']),
                $definition['sort'],
            ]);
        }
    }

    public static function all(PDO $db): array
    {
        self::seed($db);
        $stmt = $db->query("SELECT content_key, section_name, field_label, content_type, content_value, sort_order
            FROM " . self::TABLE . " ORDER BY section_name, sort_order, id");
        $content = [];
        $fields = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $value = self::decodeValue($row['content_value'], $row['content_type']);
            $content[$row['content_key']] = $value;
            $fields[] = [
                'key' => $row['content_key'],
                'section' => $row['section_name'],
                'label' => $row['field_label'],
                'type' => $row['content_type'],
                'value' => $value,
                'sort' => (int)$row['sort_order'],
            ];
        }

        return ['content' => $content, 'fields' => $fields];
    }

    public static function save(PDO $db, array $values): void
    {
        self::seed($db);
        $definitions = [];
        foreach (self::definitions() as $definition) {
            $definitions[$definition['key']] = $definition;
        }

        $stmt = $db->prepare("UPDATE " . self::TABLE . " SET content_value = ? WHERE content_key = ?");
        $db->beginTransaction();
        try {
            foreach ($values as $key => $value) {
                if (!isset($definitions[$key])) {
                    continue;
                }
                $encoded = self::encodeValue($value, $definitions[$key]['type']);
                if (strlen($encoded) > 100000) {
                    throw new InvalidArgumentException('Homepage content fields must be 100,000 characters or less.');
                }
                $stmt->execute([$encoded, $key]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
