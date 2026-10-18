<?php

namespace App\Demo;

use App\Entity\Booking;
use App\Entity\Calendar;
use App\Entity\Company;
use App\Entity\OpeningHour;
use App\Entity\Service;
use App\Entity\TimeOff;
use App\Entity\User;
use App\Enum\CompanyRole;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les entreprises de démonstration (Company::isDemo()) : deux entreprises aux réglages différents, leurs
 * calendriers, prestations, comptes et rendez-vous. Les dates sont calculées à partir d'aujourd'hui (dans le
 * fuseau de l'entreprise) pour que les agendas ne soient jamais vides.
 *
 * Chargées par les fixtures en dev, et par app:demo:reset en production. Les comptes de démo sont publics :
 * leur mot de passe est DEMO_PASSWORD, affiché sur la vitrine.
 */
final class DemoData
{
    public const string DEMO_PASSWORD = 'kairos-demo';

    /** Les liens des entreprises créées par load() */
    public const array SLUGS = ['salon-lumiere', 'cabinet-equilibre'];

    /** Tous les comptes créés par load() */
    public const array EMAILS = ['julie@salon-lumiere.test', 'marc@salon-lumiere.test', 'contact@cabinet-equilibre.test'];

    /** Les comptes de démo, affichés sur la vitrine : [adresse, ce qu'on y voit] */
    public const array ACCOUNTS = [
        'julie@salon-lumiere.test' => 'propriétaire de Salon Lumière',
        'marc@salon-lumiere.test' => 'membre, calendrier « Marc » seulement',
    ];

    private const array CUSTOMERS = [
        ['Camille Martin', 'camille.martin@example.com'],
        ['Hugo Bernard', 'hugo.bernard@example.com'],
        ['Léa Dubois', 'lea.dubois@example.com'],
        ['Nathan Petit', 'nathan.petit@example.com'],
        ['Inès Moreau', 'ines.moreau@example.com'],
        ['Lucas Laurent', 'lucas.laurent@example.com'],
        ['Chloé Garnier', 'chloe.garnier@example.com'],
    ];

    private int $customer = 0;

    /** @var array<int, string> Jour de fermeture (Y-m-d) posé sur chaque calendrier, par spl_object_id */
    private array $timeOffDays = [];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /** Crée les entreprises de démo (elles ne doivent pas exister : voir app:demo:reset) */
    public function load(ObjectManager $manager): void
    {
        $this->customer = 0;
        $this->timeOffDays = [];

        $this->loadSalon($manager);
        $this->loadCabinet($manager);

        $manager->flush();
    }

    /** Un salon de coiffure : deux coiffeurs, chacun son calendrier, ses horaires et ses prestations */
    private function loadSalon(ObjectManager $manager): void
    {
        $salon = (new Company())
            ->setName('Salon Lumière')
            ->setSlug('salon-lumiere')
            ->setIsDemo(true)
            ->setDescription('Coiffure femme et homme, coloration et soins, au cœur de la Croix-Rousse.')
            ->setAddress('12 rue d\'Austerlitz, 69004 Lyon')
            ->setAccentColor('#b4513a');
        $manager->persist($salon);

        [$coupeHomme, $brushing, $coupeFemme, $couleur, $balayage] = $this->services($manager, $salon, [
            ['Coupe homme', 30, 2500, 'Shampoing, coupe et coiffage.'],
            ['Brushing', 30, 2200, null],
            ['Coupe femme', 45, 4200, 'Shampoing, coupe et brushing.'],
            ['Couleur', 90, 6500, 'Coloration racines ou complète, avec brushing.'],
            ['Balayage', 120, 9500, 'Éclaircissement naturel, patine et brushing.'],
        ]);
        $this->services($manager, $salon, [['Soin kératine', 60, 5500, 'Bientôt de retour.']])[0]->setIsActive(false);

        // Julie : toutes les prestations, du mardi au samedi, créneaux toutes les 15 minutes
        $julie = (new Calendar())
            ->setName('Julie')
            ->setSlug('julie')
            ->setDescription('Coloriste : coupes, couleurs et balayages.')
            ->setSlotStepMinutes(15)
            ->setHorizonWeeks(6);
        foreach ([$coupeHomme, $brushing, $coupeFemme, $couleur, $balayage] as $service) {
            $julie->addService($service);
        }
        $this->hours($julie, range(2, 6), [['09:00', '12:30'], ['13:30', '19:00']]);
        $salon->addCalendar($julie);

        // Marc : coupes homme et brushings, du lundi au vendredi en continu
        $marc = (new Calendar())
            ->setName('Marc')
            ->setSlug('marc')
            ->setDescription('Coupes homme et brushings, sans interruption à midi.')
            ->addService($coupeHomme)
            ->addService($brushing);
        $this->hours($marc, range(1, 5), [['10:00', '18:00']]);
        $salon->addCalendar($marc);

        $manager->persist($this->user('julie@salon-lumiere.test', 'Julie Moreau')->setCompany($salon)->setCompanyRole(CompanyRole::Owner));
        $manager->persist($this->user('marc@salon-lumiere.test', 'Marc Lefèvre')->setCompany($salon)->setCompanyRole(CompanyRole::Member)->addCalendar($marc));

        $this->timeOff($manager, $julie, 9, 'Congés');

        $this->bookings($manager, $julie, 14, [
            [['09:30', $coupeFemme], ['14:00', $couleur]],
            [['10:00', $balayage], ['16:30', $coupeHomme]],
            [['11:00', $brushing], ['15:15', $coupeFemme]],
        ]);
        $this->bookings($manager, $marc, 14, [
            [['10:00', $coupeHomme], ['11:30', $brushing]],
            [['14:00', $coupeHomme]],
        ]);
    }

    /** Un cabinet de coaching (les données de la V1) : un seul calendrier, téléphone obligatoire */
    private function loadCabinet(ObjectManager $manager): void
    {
        $cabinet = (new Company())
            ->setName('Cabinet Équilibre')
            ->setSlug('cabinet-equilibre')
            ->setIsDemo(true)
            ->setDescription('Coaching professionnel et bilans de compétences, en cabinet ou en visio.')
            ->setAddress('8 place des Vosges, 75004 Paris')
            ->setAccentColor('#3d4f8a');
        $manager->persist($cabinet);

        $services = $this->services($manager, $cabinet, [
            ['Appel découverte', 30, 0, 'Un premier échange pour faire connaissance et définir vos objectifs.'],
            ['Séance individuelle', 60, 6000, 'La séance classique : un point complet et un plan d\'action concret.'],
            ['Séance approfondie', 90, 8500, 'Plus de temps pour travailler un sujet en profondeur.'],
        ]);
        // Désactivée : visible dans l'espace de l'entreprise, jamais proposée aux clients
        $atelier = $this->services($manager, $cabinet, [['Atelier collectif', 120, 3000, null]])[0]->setIsActive(false);

        $consultations = (new Calendar())
            ->setName('Consultations')
            ->setSlug('consultations')
            ->setDescription('Séances avec Claire Dubois, coach certifiée.')
            ->setPhoneRequired(true)
            ->setFormMessage('Indiquez en quelques mots l\'objet de votre demande : cela m\'aide à préparer notre échange.')
            ->addService($atelier);
        foreach ($services as $service) {
            $consultations->addService($service);
        }
        $this->hours($consultations, range(1, 5), [['09:00', '12:00'], ['14:00', '18:00']]);
        $this->hours($consultations, [6], [['09:00', '12:00']]);
        $cabinet->addCalendar($consultations);

        $manager->persist($this->user('contact@cabinet-equilibre.test', 'Claire Dubois')->setCompany($cabinet)->setCompanyRole(CompanyRole::Owner));

        $this->timeOff($manager, $consultations, 10, 'Formation');

        [$decouverte, $individuelle, $approfondie] = $services;
        $this->bookings($manager, $consultations, 14, [
            [['10:00', $individuelle], ['15:00', $individuelle]],
            [['09:00', $decouverte], ['14:30', $approfondie]],
            [['11:00', $individuelle]],
            [['09:30', $decouverte], ['16:00', $individuelle]],
        ]);

        // Un rendez-vous annulé : son créneau doit apparaître comme libre
        $cancelled = $this->booking($consultations, $individuelle, $this->nextOpenDay($consultations, 1)->modify('17:00'));
        $cancelled->setNote('Empêchement de dernière minute.');
        $cancelled->cancel();
        $manager->persist($cancelled);
    }

    /**
     * @param list<array{string, int, int, ?string}> $data [nom, durée, prix en centimes, description]
     *
     * @return list<Service>
     */
    private function services(ObjectManager $manager, Company $company, array $data): array
    {
        $services = [];
        foreach ($data as [$name, $duration, $price, $description]) {
            $service = (new Service())
                ->setName($name)
                ->setDurationMinutes($duration)
                ->setPriceCents($price)
                ->setDescription($description);
            $company->addService($service);
            $manager->persist($service);
            $services[] = $service;
        }

        return $services;
    }

    /**
     * @param list<int>                    $days    jours ISO (1 = lundi)
     * @param list<array{string, string}> $periods [début, fin]
     */
    private function hours(Calendar $calendar, array $days, array $periods): void
    {
        foreach ($days as $day) {
            foreach ($periods as [$start, $end]) {
                $calendar->addOpeningHour((new OpeningHour())
                    ->setDayOfWeek($day)
                    ->setStartTime(new \DateTimeImmutable($start))
                    ->setEndTime(new \DateTimeImmutable($end)));
            }
        }
    }

    /** Une journée entière de fermeture, sur un jour ouvert du calendrier, dans environ $offset jours */
    private function timeOff(ObjectManager $manager, Calendar $calendar, int $offset, string $reason): void
    {
        $day = $this->nextOpenDay($calendar, $offset);
        $this->timeOffDays[spl_object_id($calendar)] = $day->format('Y-m-d');

        $manager->persist((new TimeOff())
            ->setCalendar($calendar)
            ->setStartsAt($day)
            ->setEndsAt($day->modify('+1 day'))
            ->setReason($reason));
    }

    /**
     * Des rendez-vous sur les $days prochains jours ouverts (hors jour de fermeture), en faisant tourner les motifs.
     * À appeler après timeOff().
     *
     * @param list<list<array{string, Service}>> $patterns pour chaque jour : [heure locale, prestation]
     */
    private function bookings(ObjectManager $manager, Calendar $calendar, int $days, array $patterns): void
    {
        $pattern = 0;

        for ($offset = 1; $offset <= $days; ++$offset) {
            $day = (new \DateTimeImmutable('today', $calendar->getDateTimeZone()))->modify("+$offset days");
            if (!$this->isOpen($calendar, $day) || ($this->timeOffDays[spl_object_id($calendar)] ?? null) === $day->format('Y-m-d')) {
                continue;
            }

            foreach ($patterns[$pattern++ % \count($patterns)] as [$time, $service]) {
                $manager->persist($this->booking($calendar, $service, $day->modify($time)));
            }
        }
    }

    private function booking(Calendar $calendar, Service $service, \DateTimeImmutable $start): Booking
    {
        [$name, $email] = self::CUSTOMERS[$this->customer++ % \count(self::CUSTOMERS)];

        return (new Booking())
            ->setCalendar($calendar)
            ->setService($service)
            ->setStartsAt($start)
            ->setCustomerName($name)
            ->setCustomerEmail($email)
            ->setCustomerPhone($calendar->isPhoneRequired() ? '06 12 34 56 78' : null);
    }

    public function user(string $email, string $name): User
    {
        $user = (new User())->setEmail($email)->setName($name)->setIsVerified(true);

        return $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
    }

    /** Le premier jour ouvert du calendrier à partir d'aujourd'hui + $offset, à minuit (heure locale) */
    private function nextOpenDay(Calendar $calendar, int $offset): \DateTimeImmutable
    {
        $day = (new \DateTimeImmutable('today', $calendar->getDateTimeZone()))->modify("+$offset days");
        while (!$this->isOpen($calendar, $day)) {
            $day = $day->modify('+1 day');
        }

        return $day;
    }

    private function isOpen(Calendar $calendar, \DateTimeImmutable $day): bool
    {
        foreach ($calendar->getOpeningHours() as $hour) {
            if ($hour->getDayOfWeek() === (int) $day->format('N')) {
                return true;
            }
        }

        return false;
    }

}
