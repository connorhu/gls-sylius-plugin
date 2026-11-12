<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Form;

use CodeConjure\SyliusGlsPlugin\Form\Extension\CheckoutShipmentTypeExtension;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Pickup\PickupPointValidator;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShipment;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShippingMethod;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingLogger;
use CodeConjure\SyliusGlsPlugin\Tests\Unit\Pickup\DeliveryPointTest;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\ShipmentType;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * A pénztár szállítmány-űrlapján a rossz pont a `pickupPointId` mező HIBÁJA
 * lesz — a vevő a 2. lépésen marad, és a panelnél látja, mi a baj.
 */
final class CheckoutShipmentTypeExtensionTest extends TestCase
{
    public function testItExtendsTheCheckoutShipmentType(): void
    {
        self::assertSame([ShipmentType::class], iterator_to_array(CheckoutShipmentTypeExtension::getExtendedTypes()));
    }

    public function testAPointOfTheWrongKindIsAFieldError(): void
    {
        $form = $this->submit('gls_parcel_shop', 'HU1012-PLOCKER001');

        self::assertFalse($form->isValid());
        // A mező hibája — hogy a mezőnél vagy (a `HiddenType` alapértelmezett
        // `error_bubbling`-je miatt) a szállítmány-űrlapon jelenik-e meg, az a
        // mező beállítása, nem ezé a kiterjesztésé.
        $errors = [];

        foreach ($form->getErrors(true) as $error) {
            self::assertInstanceOf(FormError::class, $error);
            $errors[] = $error;
        }

        self::assertCount(1, $errors);
        self::assertSame(PickupPointValidator::WRONG_TYPE, $errors[0]->getMessage());
        self::assertSame($form->get('pickupPointId'), $errors[0]->getOrigin());
    }

    public function testAMatchingPointIsValid(): void
    {
        self::assertTrue($this->submit('gls_parcel_locker', 'HU1012-PLOCKER001')->isValid());
    }

    /** A host elhagyhatja a mezőt; akkor nincs mit ellenőrizni, és nem dob. */
    public function testWithoutThePickupPointFieldNothingHappens(): void
    {
        $shipment = new GlsShipment(new GlsShippingMethod('gls_parcel_shop'), 'HU1012-PLOCKER001');
        $builder = Forms::createFormFactory()->createBuilder(FormType::class, $shipment, ['data_class' => GlsShipment::class]);
        $this->extension()->buildForm($builder, []);
        $form = $builder->getForm();
        $form->submit([]);

        self::assertTrue($form->isValid());
    }

    private function submit(string $slug, string $pointId): FormInterface
    {
        $shipment = new GlsShipment(new GlsShippingMethod($slug));
        $builder = Forms::createFormFactory()
            ->createBuilder(FormType::class, $shipment, ['data_class' => GlsShipment::class])
            ->add('pickupPointId', HiddenType::class);
        $this->extension()->buildForm($builder, []);
        $form = $builder->getForm();
        $form->submit(['pickupPointId' => $pointId]);

        return $form;
    }

    private function extension(): CheckoutShipmentTypeExtension
    {
        $json = json_encode(['items' => [DeliveryPointTest::SHOP, DeliveryPointTest::LOCKER]], \JSON_THROW_ON_ERROR);
        $catalog = new DeliveryPointCatalog(new MockHttpClient(new MockResponse($json)), new ArrayAdapter(), new RecordingLogger());

        return new CheckoutShipmentTypeExtension(new PickupPointValidator($catalog), new IdentityTranslator());
    }
}
