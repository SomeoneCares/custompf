PORTNAME=	pfSense-pkg-ovp
PORTVERSION=	1.1.0
CATEGORIES=	net
MASTER_SITES=	# empty — local package

MAINTAINER=	admin@pfsense.local
COMMENT=	pfSense OpenVPN Client Importer — import .ovpn files directly from the web UI

RUN_DEPENDS=	# no external binary dependencies

NO_BUILD=	yes
NO_MTREE=	yes

SUB_FILES=	pkg-install pkg-deinstall
SUB_LIST=	PORTNAME=${PORTNAME}

do-install:
	${MKDIR} ${STAGEDIR}${PREFIX}/pkg
	${MKDIR} ${STAGEDIR}${PREFIX}/share/${PORTNAME}
	${MKDIR} ${STAGEDIR}${PREFIX}/www/packages/ovp
	${INSTALL_DATA} ${WRKSRC}/files/usr/local/pkg/ovp_import.xml \
		${STAGEDIR}${PREFIX}/pkg/
	${INSTALL_DATA} ${WRKSRC}/files/usr/local/pkg/ovp_import.inc \
		${STAGEDIR}${PREFIX}/pkg/
	${INSTALL_DATA} ${WRKSRC}/files/usr/local/share/${PORTNAME}/info.xml \
		${STAGEDIR}${PREFIX}/share/${PORTNAME}/
	${INSTALL_DATA} ${WRKSRC}/files/usr/local/www/packages/ovp/ovp_upload.php \
		${STAGEDIR}${PREFIX}/www/packages/ovp/
	${INSTALL_DATA} ${WRKSRC}/files/usr/local/www/packages/ovp/ovp_parser.php \
		${STAGEDIR}${PREFIX}/www/packages/ovp/

.include <bsd.port.mk>
